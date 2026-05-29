ALTER TABLE documents ADD COLUMN slug TEXT;
CREATE UNIQUE INDEX IF NOT EXISTS documents_slug_unique ON documents (slug);
