DROP TABLE IF EXISTS urls;

CREATE TABLE IF NOT EXISTS urls (
    id bigint PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
    name  varchar(255) NOT NULL UNIQUE,
    created_at timestamp NOT NULL
);