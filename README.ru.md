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
- `guzzlehttp/guzzle` ^7.9 и `guzzlehttp/psr7` ^2.7 (используются внутри, наружу не отдаются), `psr/http-client`, `psr/http-message`

## Установка

```bash
composer require rasuvaeff/pinned-http-client
```

## Использование

```php
use GuzzleHttp\Psr7\Request;
use Rasuvaeff\PinnedHttpClient\ClientException;
use Rasuvaeff\PinnedHttpClient\PinnedClient;
use Rasuvaeff\PinnedHttpClient\RedirectFollower;
use Rasuvaeff\PinnedHttpClient\RequestRefused;
use Rasuvaeff\PinnedHttpClient\ResponseTooLarge;

$client = new PinnedClient(); // только публичные адреса, IPv4 + IPv6, 8 с / 4 с, тело <= 10 МиБ, без прокси

try {
    $response = (new RedirectFollower($client))->sendRequest(new Request('GET', $untrustedUrl));
} catch (RequestRefused $refused) {
    $refused->reason; // Refusal::UnsupportedScheme | MissingHost | Unresolvable | AddressNotAllowed
} catch (ResponseTooLarge $tooLarge) {
    // тело (после распаковки) превысило maxBodyBytes; передача оборвана
} catch (ClientException $failed) {
    // TransferFailed (соединение, TLS, таймаут) или TooManyRedirects
}
```

Оба — обычные `Psr\Http\Client\ClientInterface`: подходят везде, где его ждут, например в `Fetcher` пакета `rasuvaeff/link-preview`, в отправку вебхуков или в свой код загрузки.

Транспорт клиент строит сам и ваш не принимает: переданный Guzzle-клиент мог бы работать на потоковом обработчике (он не знает закреплённых адресов), через прокси или с переадресациями, и гарантии не стало бы. Настраивается только типизированное:

```php
$client = new PinnedClient(
    policy: new PublicAddresses(),      // AddressPolicy::allows(string $address): bool
    resolver: new SystemResolver(),     // Resolver::resolve(string $host): list<string>
    timeoutSeconds: 8.0,
    connectTimeoutSeconds: 4.0,
    maxBodyBytes: PinnedClient::DEFAULT_MAX_BODY_BYTES, // null — без ограничения
    proxy: null,                        // 'http://egress:3128' — явно включить прокси
);

$small = $client->withMaxBodyBytes(512 * 1024);
$quick = $client->withTimeouts(2.0, 1.0);
```

**Ограничение тела считает распакованные байты**: gzip-бомба обрывается на лимите, а не после распаковки. Тело до лимита включительно приходит в ответе; байт сверх — и передача заканчивается `ResponseTooLarge`.

**Переадресации** сам `PinnedClient` не выполняет никогда: цель переадресации — новый недоверенный URL. `RedirectFollower` проходит их, отправляя каждый переход через закрепляющий клиент заново (разрешение, проверка, закрепление), превращает `303` (и `301`/`302` для `POST`) в `GET` без тела, убирает `Authorization`, `Cookie` и `Proxy-Authorization` при смене источника и останавливается после `maxRedirects` (по умолчанию 5) с `TooManyRedirects`.

### Публичный API

| Класс | Описание |
|---|---|
| `PinnedClient` | PSR-18 клиент: разрешить, проверить каждый адрес, закрепить соединение, ограничить тело. `withMaxBodyBytes()`, `withTimeouts()` |
| `RedirectFollower` | PSR-18 декоратор над `PinnedClient`: каждый переход закрепляется заново, правила метода и учётных данных, лимит переходов |
| `AddressPolicy` | `allows(string $address): bool`; сюда — свои правила |
| `PublicAddresses` | Политика по умолчанию: только публичные адреса |
| `Resolver` | `resolve(string $host): list<string>` |
| `SystemResolver` | Резолвер по умолчанию: IPv4 через `gethostbynamel()` (учитывает `/etc/hosts`) и IPv6 по AAAA-записям |
| `ClientException` | Абстрактный предок всего, что бросают клиенты; `ClientExceptionInterface`, `getRequest()` |
| `RequestRefused` | Не отправлен; `RequestExceptionInterface`; `$reason` — кейс `Refusal` |
| `TransferFailed` | Отправлен, но не удался (соединение, TLS, таймаут); `NetworkExceptionInterface`, причина в `getPrevious()` |
| `ResponseTooLarge` | Тело превысило `maxBodyBytes`; `RequestExceptionInterface`, `$limit` |
| `TooManyRedirects` | Переходов больше `maxRedirects`; `RequestExceptionInterface`, `$limit` |
| `Refusal` | `UnsupportedScheme`, `MissingHost`, `Unresolvable`, `AddressNotAllowed` |

### Политика по умолчанию

`PublicAddresses` отвергает частные сети (`10/8`, `172.16/12`, `192.168/16`), loopback, link-local (`169.254/16`, где адрес метаданных облака), CGNAT (`100.64/10`), документационные и тестовые диапазоны, multicast, зарезервированные и широковещательные, а для IPv6 — IPv4-совместимый блок `::/96` (вместе с `::` и `::1`), SIIT `::ffff:0:0:0/96`, unique-local (`fc00::/7`), link-local (`fe80::/10`), site-local, multicast, Teredo, 6to4 и документационные `2001:db8::/32` и `3fff::/20`. Адрес IPv6, несущий IPv4 (`::ffff:a.b.c.d`, NAT64 `64:ff9b::/96`), судится как этот IPv4. Всё, что не каноническая запись IP, отвергается.

Строже или мягче — своей `AddressPolicy`, например пропускающей хост разработки:

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

## Безопасность

Что делает:

- Разрешает имя один раз (IPv4 и IPv6), проверяет **каждый** адрес (один непубличный среди публичных — отказ всему узлу) и передаёт cURL ровно эти адреса. TLS по-прежнему сверяет сертификат с именем узла.
- Использует только cURL-обработчик: потоковый обработчик Guzzle, не знающий закреплённых адресов, недостижим. Ни опции запроса, ни Guzzle-клиент, ни cURL-опции снаружи не принимаются.
- Не берёт прокси из окружения (`HTTP_PROXY`, `HTTPS_PROXY`, `ALL_PROXY` и строчные варианты — их читают и Guzzle, и libcurl): прокси используется, только если передан `proxy:`.
- Ограничивает тело (после распаковки) по умолчанию.
- Пускает только `http` и `https`; отвергает URL без узла. Сообщения отказов называют причину, но не адрес.
- Переадресации — только через `RedirectFollower`, который закрепляет каждый переход заново.

Чего не может:

- С явным `proxy:` к цели подключается прокси, а не cURL: тогда SSRF-граница — прокси. Используйте egress-прокси, который не пускает во внутренние адреса.
- Имя разрешает системный резолвер до проверки политикой; враждебный резолвер вне модели угроз, и клиентского таймаута DNS нет (действуют настройки резолвера ОС).
- `timeoutSeconds` ограничивает передачу, но не разрешение имени.

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
