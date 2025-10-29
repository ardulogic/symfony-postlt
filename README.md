# Stock Reservation API — Dev & Prod Workflow

> **Note:** Do **not** run `make` with `sudo`. The Makefile auto-detects if your user can talk to Docker and falls back to `sudo docker` when needed.

## Prerequisites
- Docker Engine + Docker Compose v2
- (Optional) add your user to the `docker` group:
  ```bash
  sudo usermod -aG docker "$USER"
  newgrp docker

## Quick Start

Just launch `make` and it will display all available commands

### Dev
`make up-dev`        # build (if needed) and start api+web+db+redis

### Prod
`make up-prod`       # build (if needed) and start prod stack

### Other main commands
`make down`          # stop prod/dev stack and remove volumes
`make ps`            # view status
`make health`        # healthcheck
`make logs`          # view logs
`make clear-cache`   # clear cache
