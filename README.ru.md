# rasuvaeff/pinned-http-client

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/pinned-http-client/v)](https://packagist.org/packages/rasuvaeff/pinned-http-client)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/pinned-http-client/downloads)](https://packagist.org/packages/rasuvaeff/pinned-http-client)
[![Build](https://github.com/rasuvaeff/pinned-http-client/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/pinned-http-client/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/pinned-http-client/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/pinned-http-client/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/pinned-http-client/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/pinned-http-client/php)](https://packagist.org/packages/rasuvaeff/pinned-http-client)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

PSR-18 клиент для адресов, которые выбрали не вы: сам разрешает имя узла, проверяет каждый полученный адрес и закрепляет соединение за проверенными адресами, поэтому DNS-ответ, изменившийся между проверкой и соединением, не приведёт на внутренний узел.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.

## Зачем

URL, проверенный как `https://example.com/`, остаётся опасным, если соединение устанавливается позже: DNS может ответить проверке публичным адресом, а запросу - `127.0.0.1` или `169.254.169.254` (сервис метаданных облака). Проверка *имени* не защищает от SSRF, защищает только проверка *адреса, с которым соединились*. cURL можно сказать, каким адресам соответствует имя (`CURLOPT_RESOLVE`); этот клиент разрешает имя один раз, проверяет каждый адрес и отдаёт cURL ровно их.

## Требования

- PHP 8.3-8.5 с `curl` и `filter`
- `guzzlehttp/guzzle` ^7.9 с обработчиком cURL (он по умолчанию, когда загружен `ext-curl`), `psr/http-client`, `psr/http-message`

## Установка

```bash
composer require rasuvaeff/pinned-http-client
```

## Использование

```php
use GuzzleHttp\Psr7\Request;
use Rasuvaeff\PinnedHttpClient\PinnedClient;
use Rasuvaeff\PinnedHttpClient\PinningFailed;

$client = new PinnedClient(); // политика PublicAddresses, 8 с всего, 4 с на соединение

try {
    $response = $client->sendRequest(new Request('GET', $untrustedUrl));
} catch (PinningFailed $refused) {
    // не http(s), нет узла, имя не разрешилось, разрешилось в непубличный адрес или сбой транспорта
}
```

Это обычный `Psr\Http\Client\ClientInterface`, он подставляется везде, где нужен такой клиент: в `Fetcher` пакета `rasuvaeff/link-preview`, в отправку веб-хуков или в ваш собственный код загрузки.

**Переадресации не выполняются** (`allow_redirects` принудительно `false`): цель переадресации - новый недоверенный URL. Следуйте за переадресациями в вызывающем коде и отправляйте каждый переход через клиент заново, чтобы каждый переход был разрешён, проверен и закреплён (так делает `Fetcher` из `rasuvaeff/link-preview`).

### Публичный API

| Класс | Описание |
|---|---|
| `PinnedClient` | PSR-18 клиент: разрешить имя, проверить каждый адрес, закрепить соединение. `send($request, $guzzleOptions)` добавляет параметры Guzzle, например `stream` |
| `AddressPolicy` | `allows(string $address): bool`; сюда ставятся ваши правила |
| `PublicAddresses` | Политика по умолчанию: только публичные адреса |
| `PinningFailed` | `NetworkExceptionInterface`; запрос и причина приложены |

### Политика по умолчанию

`PublicAddresses` отвергает частные сети (`10/8`, `172.16/12`, `192.168/16`), loopback, link-local (`169.254/16`, в нём адрес метаданных облака), CGNAT (`100.64/10`), документационные и тестовые диапазоны, multicast, зарезервированные и широковещательный диапазоны, а для IPv6 - неопределённый, loopback, unique-local (`fc00::/7`), link-local (`fe80::/10`), site-local, multicast, Teredo, 6to4 и документационные диапазоны. Адрес IPv6, несущий IPv4 (`::ffff:a.b.c.d`, NAT64 `64:ff9b::/96`), оценивается как этот IPv4. Всё, что не является каноническим текстом IP, отвергается.

Строже или мягче - свой `AddressPolicy`, например пропускающий хост разработки:

```php
use Rasuvaeff\PinnedHttpClient\AddressPolicy;

final readonly class PublicOrLoopback implements AddressPolicy
{
    public function __construct(private AddressPolicy $inner) {}

    public function allows(string $address): bool
    {
        return $address === '127.0.0.1' || $this->inner->allows($address);
    }
}

$client = new PinnedClient(policy: new PublicOrLoopback(new PublicAddresses()));
```

### Резолвер, таймауты, клиент Guzzle

```php
$client = new PinnedClient(
    policy: new PublicAddresses(),
    timeoutSeconds: 5.0,
    connectTimeoutSeconds: 2.0,
    guzzle: new \GuzzleHttp\Client(),            // ваш клиент, с обработчиком cURL
    resolver: static fn (string $host): array => $myDns->ipv4($host), // список адресов текстом
);
```

Резолвер по умолчанию - `gethostbynamel()`: только IPv4, как отвечает системный резолвер. Литерал IP в URL закрепления не требует, он только проходит политику.

## Безопасность

Что делает:

- Разрешает имя один раз, проверяет **каждый** адрес (один непубличный среди публичных отвергает узел) и передаёт cURL ровно эти адреса. TLS по-прежнему проверяет сертификат по имени узла.
- Пускает только `http` и `https`; отвергает URL без узла.
- Принудительно ставит `allow_redirects: false` и закреплённые адреса: параметры, переданные в `send()`, их не переопределят (остальные, например `stream` или `timeout`, проходят).
- Политика оценивает IPv4 внутри IPv6 по вложенному IPv4 и отвергает странные записи (`127.1`, `2130706433`, `0x7f.0.0.1` системный резолвер разворачивает в `127.0.0.1`, и они отвергаются).

Чего не может:

- **Закрепление требует обработчика cURL.** У клиента Guzzle на потоковом обработчике список адресов игнорируется. Не передавайте такой клиент.
- Настроенный HTTP-прокси (`HTTP_PROXY` и подобные или параметр клиента `proxy`) означает, что соединяется прокси, а не cURL; тогда границей SSRF служит прокси. Используйте egress-прокси, отвергающий внутренние адреса, либо никакого.
- Имя разрешает системный резолвер до работы политики; враждебный резолвер вне модели угроз.
- Размер ответа не ограничивает: передайте `stream` и читайте ограниченный объём либо задайте предел в коде, читающем тело.

## Примеры

См. [examples/](examples/) - исполняемые скрипты.
Примеры должны выполняться без фатальных ошибок и соответствовать
описанному публичному API.

| Скрипт | Показывает | Нужен сервер? |
|---|---|---|
| `refusals.php` | Что политика пускает и отвергает, и отказы клиента на фиксированной таблице вместо DNS | Нет |

## Development

No PHP/Composer on the host — run in Docker via the `composer:2` image:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## License

[BSD-3-Clause](LICENSE.md)
