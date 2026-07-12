# Installation

## Requirements

- PHP `^8.3`
- Laravel `^12.0` or `^13.0`

## Install via Composer

```bash
composer require teckfuture/laravel-p6-analyzer
```

The package auto-discovers its service provider via Laravel's package discovery.

## Publish Configuration

```bash
php artisan vendor:publish --tag=p6-analyzer-config
```

This creates `config/p6-analyzer.php` with configurable thresholds and penalty weights.

## Development Installation (Path Repository)

If you're developing the package alongside a host application:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-p6-analyzer"
        }
    ],
    "require": {
        "teckfuture/laravel-p6-analyzer": "*"
    }
}
```

Then run:

```bash
composer update teckfuture/laravel-p6-analyzer
```
