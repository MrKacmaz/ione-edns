-- Runs once, when the PostgreSQL volume is first created: the test suite
-- (docker compose --profile test run --rm tests) uses its own database.
CREATE DATABASE ione_edns_testing OWNER ione_edns;
