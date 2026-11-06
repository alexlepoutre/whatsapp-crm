.PHONY: up down sh logs composer console migration migrate admin test

up:
	docker compose up -d --build

down:
	docker compose down

sh:
	docker compose exec php sh

logs:
	docker compose logs -f

composer:
	docker compose exec php composer $(filter-out $@,$(MAKECMDGOALS))

console:
	docker compose exec php bin/console $(filter-out $@,$(MAKECMDGOALS))

migration:
	docker compose exec php bin/console make:migration

migrate:
	docker compose exec php bin/console doctrine:migrations:migrate --no-interaction

admin:
	docker compose exec php bin/console app:admin:create $(filter-out $@,$(MAKECMDGOALS))

test:
	docker compose exec -e APP_ENV=test php bin/phpunit

%:
	@:
