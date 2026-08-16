# Nextcloud ExApps Build System

REGISTRY ?= ghcr.io/conductionnl
VERSION ?= 1.0.0

# ExApp directories
EXAPPS = n8n ollama open-webui

.PHONY: all clean $(EXAPPS) push help

help:
	@echo "Nextcloud ExApps Build System"
	@echo ""
	@echo "Usage:"
	@echo "  make all          - Build all ExApps"
	@echo "  make n8n          - Build n8n ExApp"
	@echo "  make ollama       - Build Ollama ExApp"
	@echo "  make open-webui   - Build Open WebUI ExApp"
	@echo "  make push         - Push all images to registry"
	@echo "  make clean        - Remove local images"
	@echo ""
	@echo "Variables:"
	@echo "  REGISTRY=$(REGISTRY)"
	@echo "  VERSION=$(VERSION)"

all: $(EXAPPS)

n8n:
	@echo "Building n8n ExApp..."
	docker build -t $(REGISTRY)/n8n-exapp:$(VERSION) -t $(REGISTRY)/n8n-exapp:latest ./n8n

ollama:
	@echo "Building Ollama ExApp..."
	docker build -t $(REGISTRY)/ollama-exapp:$(VERSION) -t $(REGISTRY)/ollama-exapp:latest ./ollama

open-webui:
	@echo "Building Open WebUI ExApp..."
	docker build -t $(REGISTRY)/open-webui-exapp:$(VERSION) -t $(REGISTRY)/open-webui-exapp:latest ./open-webui

push: all
	@echo "Pushing images to $(REGISTRY)..."
	docker push $(REGISTRY)/n8n-exapp:$(VERSION)
	docker push $(REGISTRY)/n8n-exapp:latest
	docker push $(REGISTRY)/ollama-exapp:$(VERSION)
	docker push $(REGISTRY)/ollama-exapp:latest
	docker push $(REGISTRY)/open-webui-exapp:$(VERSION)
	docker push $(REGISTRY)/open-webui-exapp:latest

clean:
	@echo "Removing local images..."
	-docker rmi $(REGISTRY)/n8n-exapp:$(VERSION) $(REGISTRY)/n8n-exapp:latest 2>/dev/null
	-docker rmi $(REGISTRY)/ollama-exapp:$(VERSION) $(REGISTRY)/ollama-exapp:latest 2>/dev/null
	-docker rmi $(REGISTRY)/open-webui-exapp:$(VERSION) $(REGISTRY)/open-webui-exapp:latest 2>/dev/null

# Development helpers
dev-n8n:
	docker run -it --rm \
		-e APP_ID=n8n \
		-e APP_SECRET=dev-secret \
		-e NEXTCLOUD_URL=http://host.docker.internal:8080 \
		-p 9001:9000 \
		-p 5678:5678 \
		$(REGISTRY)/n8n-exapp:latest

dev-ollama:
	docker run -it --rm \
		-e APP_ID=ollama \
		-e APP_SECRET=dev-secret \
		-e NEXTCLOUD_URL=http://host.docker.internal:8080 \
		-e OLLAMA_DEFAULT_MODEL=llama3.2:1b \
		-p 9002:9000 \
		-p 11434:11434 \
		--gpus all \
		$(REGISTRY)/ollama-exapp:latest

dev-open-webui:
	docker run -it --rm \
		-e APP_ID=open_webui \
		-e APP_SECRET=dev-secret \
		-e NEXTCLOUD_URL=http://host.docker.internal:8080 \
		-e OLLAMA_BASE_URL=http://host.docker.internal:11434 \
		-p 9003:9000 \
		-p 8081:8080 \
		$(REGISTRY)/open-webui-exapp:latest
