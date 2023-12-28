#SHELL := bash
#.ONESHELL:
#.SHELLFLAGS := -eu -o pipefail -c
#.DELETE_ON_ERROR:
#MAKEFLAGS += --warn-undefined-variables
#MAKEFLAGS += --no-builtin-rules

dev-up:
	docker-compose up -d

dev-down:
	docker-compose down

dev-build:
	docker-compose build

stag-build:
	docker-compose -f staging.yml build

stag-up:
	docker-compose -f staging.yml up -d

stag-down:
	docker-compose -f staging.yml down

prod-build:
	docker-compose -f production-https.yml build

prod-up:
	docker-compose -f production-https.yml up -d

prod-down:
	docker-compose -f production-https.yml down

