# agstr/pnb (pnbrad) — sources & status

## Official run method
- **Primary:** Docker Hub image `agstr/pnb` with Compose snippet in the Hub README
- URL: https://hub.docker.com/r/agstr/pnb
- Tags: https://hub.docker.com/r/agstr/pnb/tags (`latest`, `lite`, `super-lite`, `mt-container`)
- Pull: `docker pull agstr/pnb:latest` (also `docker pull agstr/pnb`)

## Companion compose / source repos
- **No dedicated AGSTR compose GitHub repo** found for pnbrad/pnb.
- Author GitHub: https://github.com/agstrxyz — repos are PHPNuxBill forks/plugins, not a Docker stack publish.
- Author fork (app source, not this all-in-one image): https://github.com/agstrxyz/phpnuxbill
- Upstream PHPNuxBill: https://github.com/hotspotbilling/phpnuxbill
- Official PHPNuxBill Docker page (empty/unhelpful at check time): https://phpnuxbill.org/getting-started/installation/docker

## Local files
- `docker-compose.yml` — copied from Docker Hub README (single service `pnbrad`, image `agstr/pnb:latest`). Not invented beyond their published snippet.
