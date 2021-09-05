# Revolt <a href="blob/main/LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square" valign="middle"></a>

Revolt is a rock-solid event loop for concurrent PHP applications.

## Motivation

Traditionally, PHP has a synchronous execution flow, doing one thing at a time.
If you query a database, you send the query and wait for the response from the database server in a blocking manner.
Once you have the response, you can start doing the next thing.

Instead of sitting there and doing nothing while waiting, we could already send the next database query, or do an HTTP call to an API.
Making use of the time we usually spend on waiting for I/O can speed up the total execution time.

A single scheduler – also called event loop – is required to allow for [cooperative multitasking](https://en.wikipedia.org/wiki/Cooperative_multitasking), which this package provides.

## Installation

This package can be installed as a [Composer](https://getcomposer.org/) dependency.

```bash
composer require revolt/event-loop
```

This installs the basic building block for building concurrent applications in PHP.

## Documentation

Documentation can be found in the [`./docs`](./docs) directory.

## Requirements

This package requires at least PHP 8.0. To take advantage of [Fibers](https://wiki.php.net/rfc/fibers), either [`ext-fiber`](https://github.com/amphp/ext-fiber) or PHP 8.1+ is required.

##### Optional Extensions

Extensions are only needed if your application necessitates a high numbers of concurrent socket connections, usually this limit is configured up to 1024 file descriptors.

- [`ev`](https://pecl.php.net/package/ev)
- [`event`](https://pecl.php.net/package/event)
- [`uv`](https://github.com/amphp/ext-uv)

## Examples

Examples can be found in the [`./examples`](./examples) directory of this repository.

## Versioning

`revolt/event-loop` follows the [semver](https://semver.org/) semantic versioning specification.

## License

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.

Revolt is the result of combining years of experience of amphp's and ReactPHP's
event loop implementations.
