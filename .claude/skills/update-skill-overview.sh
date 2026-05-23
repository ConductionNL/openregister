#!/bin/bash
# update-skill-overview.sh — Auto-updates skill-level-overview.html
#
# Auto-updates:
#   - Maturity levels (L1, L2, L3, L5, L6, L7 auto-detected)
#   - Line counts, color classes, L5/L6/L7 struct flags
#   - Skill descriptions, new rows, orphan detection
#
# L4 (Domain Knowledge) is NEVER auto-set or auto-removed.
# The script will prominently ask for your input when L4 blocks progress.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HTML="$SCRIPT_DIR/skill-level-overview.html"

[ -f "$HTML" ] || { echo "Error: $HTML not found"; exit 1; }

CHANGES=0
WARNINGS=""
L4_ACTIONS=""
declare -A disk_skills

MAX_DESC_LEN=150

color_class() {
  local n=$1
  if   (( n <= 200 )); then echo "lines-perfect"
  elif (( n <= 300 )); then echo "lines-very-few"
  elif (( n <= 400 )); then echo "lines-few"
  elif (( n <= 450 )); then echo "lines-medium"
  elif (( n <= 500 )); then echo "lines-many"
  else echo "lines-very-many"
  fi
}

get_description() {
  local raw
  raw=$(sed -n '/^---$/,/^---$/{/^description:/{s/^description: *//;s/^"//;s/"$//;s/'"'"'//g;p;}}' "$1" | head -1)
  if [ "${#raw}" -gt "$MAX_DESC_LEN" ]; then
    echo "${raw:0:$((MAX_DESC_LEN - 1))}…"
  else
    echo "$raw"
  fi
}

echo "Scanning skills in $SCRIPT_DIR ..."
echo ""

for dir in "$SCRIPT_DIR"/*/; do
  [ -d "$dir" ] || continue
  skill="$(basename "$dir")"
  md="$dir/SKILL.md"
  [ -f "$md" ] || continue

  disk_skills["$skill"]=1

  # === DETECT STRUCTURAL MARKERS ===

  lines=$(wc -l < "$md")
  cls=$(color_class "$lines")
  desc=$(get_description "$md")
  raw_desc=$(sed -n '/^---$/,/^---$/{/^description:/{s/^description: *//;s/^"//;s/"$//;s/'"'"'//g;p;}}' "$md" | head -1)

  # L1: frontmatter + numbered steps + guardrails + folder name matches
  has_frontmatter=0
  head -1 "$md" | grep -q '^---$' && has_frontmatter=1
  has_steps=0
  grep -qE '^[0-9]+\.|^#{1,4}\s*(Step|Phase)|^\*\*Step' "$md" 2>/dev/null && has_steps=1
  has_guardrails=0
  # Guardrail sections
  grep -qiE '^#{1,4}\s*(Guard|Hard Rule|Rule|Constraint|Boundar|Limitation|Warning)' "$md" 2>/dev/null && has_guardrails=1
  # Strong directives
  [ "$has_guardrails" = "0" ] && grep -qiE 'should not|must not|do not|never |IMPORTANT:|stop immediately|stop here' "$md" 2>/dev/null && has_guardrails=1
  # Common directive patterns (present in well-structured skills)
  [ "$has_guardrails" = "0" ] && grep -qiE 'must include|must comply|ensure |always |Skip if|ONLY ' "$md" 2>/dev/null && has_guardrails=1
  name_matches=0
  grep -q "^name: ${skill}$\|^name: \"${skill}\"$" "$md" 2>/dev/null && name_matches=1

  # L3: common patterns + examples/ or references/ folder
  has_model_guard=0
  grep -qiE "model:|On Haiku|on Haiku|Select.*Model|Model check|Model Recommendation|Haiku.*stop|active model" "$md" 2>/dev/null && has_model_guard=1
  has_ask=0
  grep -q "AskUserQuestion" "$md" 2>/dev/null && has_ask=1
  has_other_l3=0
  grep -qiE "acceptance_criteria|browser_snapshot|browser_navigate|## Hard Rule|## Verification|confirm.*before" "$md" 2>/dev/null && has_other_l3=1
  has_subfolder_refs=0
  grep -qiE "examples/|refs/|references/|templates/" "$md" 2>/dev/null && has_subfolder_refs=1
  has_quality_gates=0
  grep -qiE "composer check|phpcs|phpstan|make check|ruff |psalm " "$md" 2>/dev/null && has_quality_gates=1
  has_l3_patterns=0
  { [ "$has_model_guard" = "1" ] || [ "$has_ask" = "1" ] || [ "$has_other_l3" = "1" ] || [ "$has_subfolder_refs" = "1" ] || [ "$has_quality_gates" = "1" ]; } && has_l3_patterns=1
  has_examples=0
  [ -d "$dir/examples" ] && has_examples=1
  has_refs=0
  { [ -d "$dir/refs" ] || [ -d "$dir/references" ]; } && has_refs=1
  has_templates=0
  [ -d "$dir/templates" ] && has_templates=1

  # L5 markers: evals with 3+ entries
  eval_count=0; has_trigger_tests=0; has_last_validated=0; has_grading=0; has_timing=0
  if [ -f "$dir/evals/evals.json" ]; then
    eval_count=$(python3 -c "import json; d=json.load(open('${dir}evals/evals.json')); print(len(d.get('evals', [])))" 2>/dev/null || echo 0)
    # shellcheck disable=SC2034  # has_trigger_tests reserved for future maturity ring
    has_trigger_tests=$(python3 -c "import json; d=json.load(open('${dir}evals/evals.json')); tt=d.get('trigger_tests',{}); print(1 if tt.get('should_trigger') and tt.get('should_not_trigger') else 0)" 2>/dev/null || echo 0)
    has_last_validated=$(python3 -c "import json; d=json.load(open('${dir}evals/evals.json')); print(1 if d.get('last_validated') else 0)" 2>/dev/null || echo 0)
  fi
  [ -f "$dir/evals/grading.json" ] && has_grading=1
  # timing.json is produced by /skill-creator eval runs (lives in evals/workspace/.../timing.json)
  find "$dir/evals" -name "timing.json" 2>/dev/null | grep -q . && has_timing=1

  # L6 markers: learnings.md with dated entries + capture step
  has_dated_learnings=0
  [ -f "$dir/learnings.md" ] && has_dated_learnings=$(grep -cE "^- (\[2|2[0-9]{3}-|\*\*2[0-9]{3}-)" "$dir/learnings.md" 2>/dev/null || echo 0)
  has_capture=0
  grep -q "Capture Learnings" "$md" 2>/dev/null && has_capture=1

  # L7 markers: agent spawning
  has_agent=0
  grep -qE "Agent tool|subagent|sub-agent|Launch.*agent|Task agents" "$md" 2>/dev/null && has_agent=1

  # Struct flags (for orange rings)
  l5=0; [ "$eval_count" -ge 3 ] && l5=1
  l6=0; [ -f "$dir/learnings.md" ] && [ "$has_capture" = "1" ] && l6=1
  l7=0; [ "$has_agent" = "1" ] && l7=1

  # === ADD NEW ROW IF MISSING ===

  if ! grep -q ">${skill}<" "$HTML"; then
    tbody_end=$(grep -n '</tbody>' "$HTML" | head -1 | cut -d: -f1)
    extra_attrs=""
    [ "$l5" = "1" ] && extra_attrs+=' data-l5struct="1"'
    [ "$l6" = "1" ] && extra_attrs+=' data-l6infra="1"'
    [ "$l7" = "1" ] && extra_attrs+=' data-l7struct="1"'
    sed -i "${tbody_end}i\\<tr data-maturity=\"1\" data-structural=\"1\" data-target=\"3\"${extra_attrs}>\n  <td><a href=\"${skill}/SKILL.md\">${skill}</a></td><td>${desc}</td>\n  <td class=\"level-cell\"></td>\n  <td class=\"target-cell\"><span class=\"target-badge tl3\">L3</span></td>\n  <td class=\"lines ${cls}\">${lines}</td>\n</tr>" "$HTML"
    echo "  ${skill}: NEW ROW (M=1 S=1 T=3 — set target manually)"
    CHANGES=$((CHANGES + 1))
  fi

  # === FIND ROW IN HTML ===

  td_line=$(grep -n ">${skill}<" "$HTML" | head -1 | cut -d: -f1)
  tr_line=$((td_line - 1))
  lines_td=$((td_line + 3))

  cur_tr=$(sed -n "${tr_line}p" "$HTML")
  cur_lines_td=$(sed -n "${lines_td}p" "$HTML")
  cur_count=$(echo "$cur_lines_td" | grep -oP '>\K\d+(?=<)' || echo "?")
  cur_cls=$(echo "$cur_lines_td" | grep -oP 'lines \K[^"]+' || echo "?")
  cur_m=$(echo "$cur_tr" | grep -oP 'data-maturity="\K\d+' || echo 0)

  # === COMPUTE MATURITY ===

  # Each level detected per writing-skills.md criteria
  l1_met=0  # frontmatter + steps + guardrails + name matches
  [ "$has_frontmatter" = "1" ] && [ "$has_steps" = "1" ] && [ "$has_guardrails" = "1" ] && [ "$name_matches" = "1" ] && l1_met=1

  l2_met=0  # under 500 lines + description exists
  [ "$lines" -le 500 ] && [ -n "$raw_desc" ] && l2_met=1

  l3_met=0  # common patterns + (examples/ or references/)
  [ "$has_l3_patterns" = "1" ] && { [ "$has_examples" = "1" ] || [ "$has_refs" = "1" ] || [ "$has_templates" = "1" ]; } && l3_met=1

  # L4: MANUAL — trust the current value, never auto-change
  l4_confirmed=0
  [ "$cur_m" -ge 4 ] 2>/dev/null && l4_confirmed=1

  l5_met=0; enough_triggers=0
  if [ "$eval_count" -ge 3 ]; then
    enough_triggers=$(python3 -c "import json; d=json.load(open('${dir}evals/evals.json')); tt=d.get('trigger_tests',{}); print(1 if len(tt.get('should_trigger',[]))>=10 and len(tt.get('should_not_trigger',[]))>=10 else 0)" 2>/dev/null || echo 0)
    [ "$enough_triggers" = "1" ] && [ "$has_last_validated" = "1" ] && [ "$has_grading" = "1" ] && [ "$has_timing" = "1" ] && l5_met=1
  fi

  l6_met=0
  [ "$has_dated_learnings" -gt 0 ] 2>/dev/null && [ "$has_capture" = "1" ] && l6_met=1

  l7_met=0
  [ "$has_agent" = "1" ] && l7_met=1

  # Cumulative chain (each level requires all below)
  new_m=0
  [ "$l1_met" = "1" ] && new_m=1
  [ "$new_m" -ge 1 ] && [ "$l2_met" = "1" ] && new_m=2
  [ "$new_m" -ge 2 ] && [ "$l3_met" = "1" ] && new_m=3
  [ "$new_m" -ge 3 ] && [ "$l4_confirmed" = "1" ] && new_m=4
  [ "$new_m" -ge 4 ] && [ "$l5_met" = "1" ] && new_m=5
  [ "$new_m" -ge 5 ] && [ "$l6_met" = "1" ] && new_m=6
  [ "$new_m" -ge 6 ] && [ "$l7_met" = "1" ] && new_m=7

  # L4 blocking detection
  if [ "$new_m" = "3" ] && [ "$l4_confirmed" = "0" ]; then
    blocked=""
    [ "$l5_met" = "1" ] && blocked+="L5 "
    [ "$l6_met" = "1" ] && blocked+="L6 "
    [ "$l7_met" = "1" ] && blocked+="L7 "
    if [ "$has_refs" = "1" ]; then
      L4_ACTIONS+="\n  ${skill}:  L1-L3 achieved + refs/ detected → CONFIRM L4 to unlock ${blocked:-higher levels}"
    else
      L4_ACTIONS+="\n  ${skill}:  L1-L3 achieved → SET L4 (domain knowledge) to unlock ${blocked:-higher levels}"
    fi
  fi

  # === UPDATE MATURITY ===

  if [ "$new_m" != "$cur_m" ] 2>/dev/null; then
    sed -i "${tr_line}s|data-maturity=\"${cur_m}\"|data-maturity=\"${new_m}\"|" "$HTML"
    if [ "$new_m" -gt "$cur_m" ]; then
      echo "  ${skill}: maturity M${cur_m} -> M${new_m} ↑"
    else
      echo "  ${skill}: maturity M${cur_m} -> M${new_m} ↓"
    fi
    CHANGES=$((CHANGES + 1))
    cur_tr=$(sed -n "${tr_line}p" "$HTML")
  fi

  # === UPDATE LINE COUNT ===

  if [ "$cur_count" != "$lines" ] || [ "$cur_cls" != "$cls" ]; then
    sed -i "${lines_td}s|<td class=\"lines [^\"]*\">[0-9]*</td>|<td class=\"lines ${cls}\">${lines}</td>|" "$HTML"
    echo "  ${skill}: lines ${cur_count} -> ${lines} (${cls})"
    CHANGES=$((CHANGES + 1))
  fi

  # === UPDATE STRUCT FLAGS ===

  # L5
  has_l5s=0; echo "$cur_tr" | grep -q 'l5struct' && has_l5s=1
  if [ "$l5" = "1" ] && [ "$has_l5s" = "0" ]; then
    sed -i "${tr_line}s|>$| data-l5struct=\"1\">|" "$HTML"
    echo "  ${skill}: +L5 struct (evals)"
    CHANGES=$((CHANGES + 1))
    cur_tr=$(sed -n "${tr_line}p" "$HTML")
  elif [ "$l5" = "0" ] && [ "$has_l5s" = "1" ]; then
    sed -i "${tr_line}s| data-l5struct=\"1\"||" "$HTML"
    echo "  ${skill}: -L5 struct"
    CHANGES=$((CHANGES + 1))
    cur_tr=$(sed -n "${tr_line}p" "$HTML")
  fi

  # L6
  has_l6i=0; echo "$cur_tr" | grep -q 'l6infra' && has_l6i=1
  if [ "$l6" = "1" ] && [ "$has_l6i" = "0" ]; then
    sed -i "${tr_line}s|>$| data-l6infra=\"1\">|" "$HTML"
    echo "  ${skill}: +L6 infra"
    CHANGES=$((CHANGES + 1))
    cur_tr=$(sed -n "${tr_line}p" "$HTML")
  elif [ "$l6" = "0" ] && [ "$has_l6i" = "1" ]; then
    sed -i "${tr_line}s| data-l6infra=\"1\"||" "$HTML"
    echo "  ${skill}: -L6 infra"
    CHANGES=$((CHANGES + 1))
    cur_tr=$(sed -n "${tr_line}p" "$HTML")
  fi

  # L7
  has_l7s=0; echo "$cur_tr" | grep -q 'l7struct' && has_l7s=1
  if [ "$l7" = "1" ] && [ "$has_l7s" = "0" ]; then
    sed -i "${tr_line}s|>$| data-l7struct=\"1\">|" "$HTML"
    echo "  ${skill}: +L7 struct (agents)"
    CHANGES=$((CHANGES + 1))
    cur_tr=$(sed -n "${tr_line}p" "$HTML")
  elif [ "$l7" = "0" ] && [ "$has_l7s" = "1" ]; then
    sed -i "${tr_line}s| data-l7struct=\"1\"||" "$HTML"
    echo "  ${skill}: -L7 struct"
    CHANGES=$((CHANGES + 1))
    cur_tr=$(sed -n "${tr_line}p" "$HTML")
  fi

  # === UPDATE DESCRIPTION ===

  if [ -n "$desc" ]; then
    cur_desc=$(sed -n "${td_line}p" "$HTML" | grep -oP '</td><td>\K[^<]*')
    if [ -n "$cur_desc" ] && [ "$cur_desc" != "$desc" ]; then
      safe_old=$(printf '%s' "$cur_desc" | sed 's|[&/\]|\\&|g')
      safe_new=$(printf '%s' "$desc" | sed 's|[&/\]|\\&|g')
      sed -i "${td_line}s|</td><td>${safe_old}</td>|</td><td>${safe_new}</td>|" "$HTML"
      echo "  ${skill}: description updated"
      CHANGES=$((CHANGES + 1))
    fi
  fi

  # === WARNINGS ===

  [ "$lines" -gt 500 ] && [ "$new_m" -ge 2 ] 2>/dev/null && \
    WARNINGS+="\n  ${skill}: ${lines} lines (>500) — L2 violated, maturity capped"
  [ "$l5" = "1" ] && [ "$enough_triggers" = "0" ] && \
    WARNINGS+="\n  ${skill}: evals present but trigger_tests need 10+ entries each — expand to unlock L5"
  [ "$l5" = "1" ] && [ "$enough_triggers" = "1" ] && [ "$has_last_validated" = "0" ] && \
    WARNINGS+="\n  ${skill}: evals structured correctly (orange ring) but last_validated is null — run evals and set last_validated to unlock L5"
  [ "$l5" = "1" ] && [ "$enough_triggers" = "1" ] && [ "$has_last_validated" = "1" ] && [ "$has_grading" = "0" ] && \
    WARNINGS+="\n  ${skill}: last_validated is set but grading.json is missing — run evals via /skill-creator to unlock L5 (see writing-skills.md)"
  [ "$l5" = "1" ] && [ "$enough_triggers" = "1" ] && [ "$has_last_validated" = "1" ] && [ "$has_grading" = "1" ] && [ "$has_timing" = "0" ] && \
    WARNINGS+="\n  ${skill}: grading.json present but no timing.json found — run evals via /skill-creator to produce timing data and unlock L5 (see writing-skills.md)"

done

# === ORPHANED ROWS ===

while IFS= read -r html_skill; do
  [ -z "$html_skill" ] && continue
  if [ -z "${disk_skills[$html_skill]+x}" ]; then
    WARNINGS+="\n  ORPHAN: ${html_skill} is in the HTML but skill directory not found — remove row?"
  fi
done < <(grep -B1 'level-cell' "$HTML" | grep -oP '>\K[a-z][-a-z0-9]*(?=<)')

# === OUTPUT ===

echo ""
echo "${CHANGES} auto-updates applied."

if [ -n "$L4_ACTIONS" ]; then
  echo ""
  echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!"
  echo "!!!  ACTION REQUIRED: L4 (Domain Knowledge) needs your input  !!!"
  echo "!!!                                                           !!!"
  echo "!!!  L4 is never auto-set. These skills have L1-L3 achieved   !!!"
  echo "!!!  but are stuck at M3 because L4 hasn't been confirmed.    !!!"
  echo "!!!  To unlock higher maturity, manually set data-maturity    !!!"
  echo "!!!  to 4 or higher in the HTML after verifying domain        !!!"
  echo "!!!  knowledge is present.                                    !!!"
  echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!"
  echo -e "$L4_ACTIONS"
  echo ""
fi

if [ -n "$WARNINGS" ]; then
  echo ""
  echo "=== WARNINGS ==="
  echo -e "$WARNINGS"
  echo ""
fi
