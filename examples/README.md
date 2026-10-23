# Examples

Run examples from the package root after installing dependencies. None of them needs the network:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/refusals.php
```

| Script | Shows | Needs server? |
|---|---|---|
| `refusals.php` | What the policy allows and refuses, and the client's refusals against a fixed table standing in for DNS | No |
