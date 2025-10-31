# Symfony API Crud Scaffold

## Prerequisites
- Prepare Docker Engine + Docker Compose v2
- `sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
`
- `sudo systemctl enable --now docker`
- (Optional) add your user to the `docker` group:
  ```bash
  sudo usermod -aG docker "$USER"
  newgrp docker

## Quick Start

### Development Environment

> **Note:** Do **not** run `make` with `sudo`. The Makefile auto-detects if your user can talk to Docker and falls back to `sudo docker` when needed.

Build and run the container (it will take some time):
- `make up-dev`

Create and seed the database with demo data:
- `make fresh-seed-dev`

Run unit tests:
- `make test-dev`

#### Additionally:
Inspect container status:
- `make ps-dev`

Stop container:
- `make down-dev`

Open shell in container:  
- `sh-dev`


### Production Environment

Same logic applies to production environment too.
Simply run `make` to see all commands.
