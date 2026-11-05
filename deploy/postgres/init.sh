#!/bin/bash
# Runs once, on the first start of an empty data volume.
#   quick_school_ai  owned by qsa_app: NOSUPERUSER NOBYPASSRLS, so Row-Level Security applies
#                    (the ERP forces RLS even for the table owner)
#   qsa_ai           owned by qsa_ai, with the pgvector extension for the AI knowledge base
set -euo pipefail
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
     -v app_pw="$APP_DB_PASSWORD" -v ai_pw="$AI_DB_PASSWORD" <<'SQL'
CREATE ROLE qsa_app LOGIN PASSWORD :'app_pw' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
CREATE DATABASE quick_school_ai OWNER qsa_app ENCODING 'UTF8' TEMPLATE template0;
CREATE ROLE qsa_ai LOGIN PASSWORD :'ai_pw' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
CREATE DATABASE qsa_ai OWNER qsa_ai ENCODING 'UTF8' TEMPLATE template0;
SQL
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname qsa_ai -c 'CREATE EXTENSION IF NOT EXISTS vector'
