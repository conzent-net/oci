# Query cookbook

Copy-paste bodies for the support agent. All examples target the `conzent-kb` alias.

---

## 1. Hybrid search (the default)

Reciprocal Rank Fusion over one BM25 retriever and two kNN retrievers. Elasticsearch 8.15+.

```json
POST /conzent-kb/_search
{
  "retriever": {
    "rrf": {
      "rank_window_size": 50,
      "rank_constant": 20,
      "retrievers": [
        {
          "standard": {
            "query": {
              "multi_match": {
                "query": "{{user_question}}",
                "fields": ["questions^4", "title^3", "fields_covered^2", "summary^1.5", "body"],
                "type": "best_fields",
                "fuzziness": "AUTO"
              }
            }
          }
        },
        {
          "knn": {
            "field": "questions_vector",
            "query_vector": [],
            "k": 25,
            "num_candidates": 100
          }
        },
        {
          "knn": {
            "field": "body_vector",
            "query_vector": [],
            "k": 25,
            "num_candidates": 100
          }
        }
      ]
    }
  },
  "size": 4,
  "_source": ["id", "title", "url", "menu_path", "area", "edition", "plan", "summary", "body", "related"]
}
```

`query_vector` is the embedding of `{{user_question}}` from the same model used at index time.

---

## 2. Hybrid search, edition-scoped

When you know the user is self-hosted (they pasted their own domain, or their account says so),
filter first — otherwise you will confidently send them to a page that does not exist.

```json
POST /conzent-kb/_search
{
  "retriever": {
    "rrf": {
      "retrievers": [
        {
          "standard": {
            "query": {
              "bool": {
                "must": [
                  {
                    "multi_match": {
                      "query": "{{user_question}}",
                      "fields": ["questions^4", "title^3", "fields_covered^2", "body"],
                      "fuzziness": "AUTO"
                    }
                  }
                ],
                "filter": [{ "term": { "edition": "self-hosted" } }]
              }
            }
          }
        },
        {
          "knn": {
            "field": "questions_vector",
            "query_vector": [],
            "k": 25,
            "num_candidates": 100,
            "filter": { "term": { "edition": "self-hosted" } }
          }
        }
      ]
    }
  },
  "size": 4
}
```

---

## 3. BM25-only fallback (no embedding service)

Works against the same index when `KB_EMBED_URL` was never configured.

```json
POST /conzent-kb/_search
{
  "query": {
    "multi_match": {
      "query": "{{user_question}}",
      "fields": ["questions^4", "title^3", "fields_covered^2", "summary^1.5", "body", "tags^2"],
      "type": "best_fields",
      "fuzziness": "AUTO",
      "minimum_should_match": "2<70%"
    }
  },
  "size": 4,
  "highlight": {
    "fields": { "body": { "fragment_size": 300, "number_of_fragments": 2 } }
  }
}
```

---

## 4. "Where do I find X?" — navigation lookup

When the user names a UI control rather than describing a problem.

```json
POST /conzent-kb/_search
{
  "query": {
    "bool": {
      "should": [
        { "match_phrase": { "fields_covered": { "query": "{{control_name}}", "boost": 5 } } },
        { "match": { "fields_covered": "{{control_name}}" } },
        { "match": { "title": "{{control_name}}" } }
      ],
      "minimum_should_match": 1
    }
  },
  "size": 3,
  "_source": ["id", "title", "url", "menu_path", "area"]
}
```

Answer template: *"{{title}} — go to **{{menu_path}}** (`{{url}}`)."*

---

## 5. Browse one area

For "show me everything about banners".

```json
POST /conzent-kb/_search
{
  "query": { "term": { "area": "Banner" } },
  "sort": [{ "id": "asc" }],
  "size": 50,
  "_source": ["id", "title", "url", "menu_path", "summary"]
}
```

---

## 6. Facet counts (admin dashboard)

```json
POST /conzent-kb/_search
{
  "size": 0,
  "aggs": {
    "by_area":    { "terms": { "field": "area", "size": 30 } },
    "by_edition": { "terms": { "field": "edition", "size": 5 } },
    "by_plan":    { "terms": { "field": "plan", "size": 5 } },
    "unreviewed": { "filter": { "term": { "reviewed": false } } }
  }
}
```

---

## 7. Follow the graph

After picking a hit, pull its siblings so the agent can offer next steps. `related` holds
article **ids**, so this resolves them in one call.

```json
POST /conzent-kb/_search
{
  "query": { "terms": { "id": ["banner.layout", "banner.content", "sites.frameworks"] } },
  "_source": ["id", "title", "url", "menu_path", "summary"]
}
```

---

## 8. Resolve a `Knowledgebase: X - Document: Y.md` reference

Article bodies reference each other by knowledge base and document name rather than by path.
When the agent reads e.g. *"See Knowledgebase: Sites - Document: install-script.md"* and wants
to pull that document, match on `knowledgebase` plus the file name.

The document name is not stored as its own field — it is the last segment of the article's
source path — so match it against `id` when you know the mapping, or keep a lookup built from
INDEX.md. The robust form, using the title/body:

```json
POST /conzent-kb/_search
{
  "query": {
    "bool": {
      "filter": [{ "term": { "knowledgebase": "Sites" } }],
      "should": [
        { "match_phrase": { "title": "Installing the consent script" } },
        { "match": { "body": "install script" } }
      ],
      "minimum_should_match": 1
    }
  },
  "size": 1
}
```

Simplest and most reliable: keep the `id` in `related` as the join key and use query 7. The
prose reference is for the human reading the answer; `related` is for the machine.

---

## 9. Scope retrieval to one knowledge base

Each folder ships as its own knowledge base, so you can narrow a search to just one.

```json
POST /conzent-kb/_search
{
  "query": {
    "bool": {
      "must": [
        {
          "multi_match": {
            "query": "{{user_question}}",
            "fields": ["questions^4", "title^3", "fields_covered^2", "body"],
            "fuzziness": "AUTO"
          }
        }
      ],
      "filter": [{ "term": { "knowledgebase": "Banner" } }]
    }
  },
  "size": 4
}
```

Facet the available knowledge bases with:

```json
POST /conzent-kb/_search
{ "size": 0, "aggs": { "kbs": { "terms": { "field": "knowledgebase", "size": 20 } } } }
```

---

## Answer-shaping rules

- Lead with the click-path. `menu_path` then `url`, before any explanation.
- On self-hosted, prefix `url` with the customer's own host — never `app.getconzent.com`.
- If `plan` is `business` or `paid`, say the feature exists and what it needs, don't pretend
  it is unavailable.
- If the best RRF score is weak, say the KB does not cover it and name the closest article.
  Do not synthesise product behaviour.
