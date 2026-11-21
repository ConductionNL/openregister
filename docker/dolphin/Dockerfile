# Dockerfile for ByteDance Dolphin Document Parser
# This creates a REST API for Dolphin document parsing

FROM nvidia/cuda:12.1.0-runtime-ubuntu22.04@sha256:402700b179eb764da6d60d99fe106aa16c36874f7d7fb3e122251ff6aea8b2f7

ENV DEBIAN_FRONTEND=noninteractive
ENV PYTHONUNBUFFERED=1

# Install system dependencies
RUN apt-get update && apt-get install -y \
    python3.10 \
    python3-pip \
    git \
    curl \
    wget \
    libgl1-mesa-glx \
    libglib2.0-0 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Clone Dolphin at a fixed commit; requirements.in names the same commit
ARG DOLPHIN_COMMIT=befa5dad986f86396b73cbd8c37e557b5770c902
RUN git clone https://github.com/bytedance/Dolphin.git /app/dolphin && \
    git -C /app/dolphin checkout --detach "${DOLPHIN_COMMIT}"

# Install Dolphin's and the API server's Python dependencies, hash-checked
WORKDIR /app/dolphin
COPY requirements.lock /app/requirements.lock
RUN pip3 install --no-cache-dir --require-hashes -r /app/requirements.lock
RUN apt-get update && apt-get install -y poppler-utils && rm -rf /var/lib/apt/lists/*

# Download model
RUN python3 -c "from huggingface_hub import snapshot_download; \
    snapshot_download(repo_id='ByteDance/Dolphin-1.5', local_dir='/app/models')"

# Copy API server
COPY api_server.py /app/api_server.py

WORKDIR /app

EXPOSE 5000

HEALTHCHECK --interval=30s --timeout=10s --start-period=120s --retries=3 \
    CMD curl -f http://localhost:5000/health || exit 1

CMD ["python3", "api_server.py"]

