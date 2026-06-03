# ===========================================================================
# Makefile: scorciatoie per il ciclo di vita del progetto.
# Tutti i comandi PHP girano DENTRO i container per garantire PHP 7.3.
# ===========================================================================
DC := docker-compose
APP := $(DC) exec app

.PHONY: help up down build install setup migrate fresh seed demo worker logs test shell client

help: ## Mostra questo aiuto
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

build: ## Builda le immagini Docker
	$(DC) build

up: ## Avvia tutto lo stack (web + worker + mysql + redis)
	$(DC) up -d

down: ## Ferma e rimuove i container
	$(DC) down

install: ## Installa le dipendenze Composer dentro il container
	$(APP) composer install

setup: up ## Setup completo: avvia, installa, migra ed esegue i seed di base
	$(APP) composer install
	$(APP) cp -n .env.example .env || true
	$(APP) php artisan migrate --force
	@echo "==> Stack pronto su http://localhost:8080"

migrate: ## Esegue le migration
	$(APP) php artisan migrate --force

fresh: ## Ricrea il database da zero
	$(APP) php artisan migrate:fresh --force

seed: ## Seed di base (una versione + dati minimi)
	$(APP) php artisan db:seed --force

demo: ## Genera un dataset demo grande (parametrizzabile: make demo ARGS="--players=100000 --events=2000000")
	$(APP) php artisan demo:seed $(ARGS)

worker: ## Avvia un worker di coda in foreground (debug)
	$(APP) php artisan queue:work --tries=3 --timeout=3600 -v

logs: ## Segue i log dei container
	$(DC) logs -f --tail=100

test: ## Esegue la suite PHPUnit
	$(APP) ./vendor/bin/phpunit

shell: ## Apre una shell nel container app
	$(APP) bash

client: ## Esegue il client di esempio end-to-end (ingestione + export + download)
	$(APP) php client/client.php
