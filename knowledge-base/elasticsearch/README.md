# Elasticsearch: indexing and retrieval

The support agent already reads from Elasticsearch — `src/Admin/Service/KnowledgeBaseService.php`
searches the `conzent-kb` index, and `/admin/kb` lists and edits its documents. This directory
extends that index with the structure needed for reliable retrieval: faceted metadata, a
dedicated `questions` field, and dense vectors for semantic search.

---

## Why the index changes

The existing mapping is dynamic and flat: `title`, `body`, `tags`, `audience`, `source`,
`reviewed`, `createdAt`. Three things are missing for an agent answering support questions:

1. **No location metadata.** The agent can quote the answer but cannot tell the user *where to
   click*. `url` + `menu_path` fix that. `knowledgebase` additionally records which of the
   eleven knowledge bases a document ships in, so retrieval can be scoped to one.
2. **No edition/plan filter.** Half the answers are wrong for the other edition — a self-hosted
   user has no `/billing` page and no agency features. `edition` and `plan` make that filterable.
3. **Keyword-only matching.** BM25 misses paraphrases ("stop the popup appearing in the US"
   never lexically matches "geo targeting"). The `questions` field plus vectors close that gap.

Legacy fields are all preserved, so `/admin/kb` and `KnowledgeBaseService` keep working.

---

## Setup

Create the new index and point the existing alias at it:

```bash
ES=${ELASTICSEARCH_URL:-http://localhost:9200}

# 1. create v2 with the full mapping
curl -X PUT "$ES/conzent-kb-v2" \
  -H 'Content-Type: application/json' \
  --data-binary @knowledge-base/elasticsearch/mapping.conzent-kb.json

# 2. (optional) install the normalisation pipeline
curl -X PUT "$ES/_ingest/pipeline/conzent-kb-normalise" \
  -H 'Content-Type: application/json' \
  --data-binary @knowledge-base/elasticsearch/ingest-pipeline.json

# 3. move existing hand-written articles across, if any
curl -X POST "$ES/_reindex" -H 'Content-Type: application/json' -d '{
  "source": {"index": "conzent-kb"},
  "dest":   {"index": "conzent-kb-v2", "pipeline": "conzent-kb-normalise"}
}'

# 4. swap the alias — the app keeps querying "conzent-kb"
curl -X DELETE "$ES/conzent-kb"          # only if it is a concrete index, not already an alias
curl -X POST "$ES/_aliases" -H 'Content-Type: application/json' -d '{
  "actions": [{"add": {"index": "conzent-kb-v2", "alias": "conzent-kb"}}]
}'
```

Then import the articles:

```bash
php knowledge-base/elasticsearch/import-kb.php            # upsert changed articles
php knowledge-base/elasticsearch/import-kb.php --recreate # wipe and rebuild
php knowledge-base/elasticsearch/import-kb.php --dry-run  # parse + validate only
```

The `_comment` keys in the mapping are stripped by the importer's `--recreate` path; if you
`curl` the mapping directly, Elasticsearch 8 rejects unknown top-level keys — use
`import-kb.php --recreate`, or strip them with
`jq 'del(.._comment)' mapping.conzent-kb.json`.

---

## Embeddings

The mapping declares two `dense_vector` fields at **768 dims**. Change `dims` on *both*
`body_vector` and `questions_vector` to match your model:

| Model | dims | Notes |
|---|---|---|
| `intfloat/e5-base-v2`, `thenlper/gte-base` | 768 | default here; runs locally, no API cost |
| `intfloat/e5-large-v2` | 1024 | better recall on paraphrase, ~3× slower |
| OpenAI `text-embedding-3-small` | 1536 | cheapest hosted option |
| Voyage `voyage-3-lite` | 512 | strong on short technical text |

`import-kb.php` reads `KB_EMBED_URL` / `KB_EMBED_MODEL` / `KB_EMBED_KEY` from the environment
and POSTs to any OpenAI-compatible `/v1/embeddings` endpoint. Leave `KB_EMBED_URL` unset and the
importer indexes without vectors — BM25-only retrieval still works, just less forgiving of
paraphrase.

Two vectors, not one, because they answer different query shapes:

- `questions_vector` matches the user's conversational phrasing ("why is my banner not showing").
- `body_vector` matches descriptive queries ("consent expiration setting").

Query both, fuse with RRF.

---

## Chunking

**Don't chunk.** Each article is deliberately sized to fit a single embedding window
(100–200 lines, well under 8k tokens) and is topically coherent — one page or one settings
section per document. Splitting them would separate a field from its "Where to find it" header,
which is exactly the context the agent needs to give a usable answer.

If an article ever outgrows the window, split it into two articles with their own `id`s rather
than chunking one document.

---

## Retrieval contract for the agent

1. Hybrid retrieve: BM25 (`questions^4`, `title^3`, `fields_covered^2`, `body`, `summary`) +
   kNN on both vectors, fused with RRF. Take the top 4.
2. Filter by `edition` when the user's edition is known (from their account or from the URL
   they pasted). Never filter on `plan` — a locked feature is still a valid answer, phrased as
   "that requires the Business plan".
3. Answer from `body`. Quote `menu_path` and `url` so the user can navigate.
4. If the top hit scores below your threshold, say so and offer the nearest article rather than
   improvising — this KB is deliberately narrow and admits what it does not cover.

Full query bodies: [queries.md](queries.md).
