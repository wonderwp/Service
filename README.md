# WonderWp Service Component

A WonderWp package containing abstract services, interfaces and implementations.
Most reserved WonderWp services extend those definitions.

## Testing

See the framework guide: [wonderwp/docs/testing.md](https://github.com/wonderwp/wonderwp/blob/chore/deps-max/docs/testing.md).

### Unit (CI)

```bash
composer install
composer test
```

No Integration suite is required for this package (pure PHP / DI helpers).
