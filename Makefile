.PHONY: quality coverage benchmark-build benchmark-build-with benchmark-build-without benchmark-run-with benchmark-run-without benchmark-stop benchmark-hit benchmark-compose-up benchmark-compose-down benchmark-proper

quality:
	composer quality

coverage:
	composer test-coverage

benchmark-build: benchmark-build-with benchmark-build-without

benchmark-build-with:
	docker build -f docker/with-package/Dockerfile -t hybrid-cache-with-package .

benchmark-build-without:
	docker build -f docker/without-package/Dockerfile -t hybrid-cache-without-package .

benchmark-run-with:
	docker run --rm -d --name hybrid-cache-with-package -p 8081:8000 hybrid-cache-with-package

benchmark-run-without:
	docker run --rm -d --name hybrid-cache-without-package -p 8082:8000 hybrid-cache-without-package

benchmark-stop:
	-docker rm -f hybrid-cache-with-package hybrid-cache-without-package

benchmark-hit:
	curl "http://127.0.0.1:8081/benchmark?ttl=2&stale=5&work_ms=120"
	curl "http://127.0.0.1:8082/benchmark?ttl=2&work_ms=120"

benchmark-compose-up:
	docker compose -f docker-compose.benchmark.yml up --build -d

benchmark-compose-down:
	docker compose -f docker-compose.benchmark.yml down --remove-orphans

benchmark-proper:
	bash scripts/run-benchmark.sh
