# Contributing

Bug reports and pull requests are welcome.

## Layout

- `Shopify/` is the module itself, exactly what ends up in `Modules/Shopify`.
- `dev/tests/` holds the standalone PHP tests (`php dev/tests/run.php`, no FreeScout or network needed).

## Releasing

1. Bump `version` in `Shopify/module.json` and in `VERSION` (same number) and add an entry to `CHANGELOG.md`.
2. Commit and push to `main`.
3. Tag and push: `git tag v1.0.1 && git push origin v1.0.1`.
4. The GitHub Action builds `Shopify.zip` and publishes the release; FreeScout then offers the update in Manage > Modules.
