# Componenta CQRS Lock

Блокировка ресурсов для CQRS-команд: строковые шаблоны и нативные PHP callback в `#[Lock]`.

[English documentation](README.md)

## Требования и регистрация

Ветка v4 требует PHP **8.5.13+**, Componenta CQRS **5**, Componenta Config **4** и Symfony Lock **8.1+** в пределах ветки 8.x.

```bash
composer require componenta/cqrs-lock
```

Зарегистрируйте провайдеры, настройте `Symfony\Component\Lock\LockFactory` в контейнере и добавьте `Componenta\CQRS\Command\Middleware\ResourceLockMiddleware` в цепочку middleware команд:

```php
return [
    new Componenta\CQRS\ConfigProvider(),
    new Componenta\CQRS\Lock\ConfigProvider(),
];
```

Фабрика использует настроенный `LockFactory` и CQRS `CommandMetadataProviderInterface`. Стандартный провайдер кеширует reflection-дескрипторы и создает новый экземпляр атрибута при каждом запросе. Тело callback выполняется при обработке команды, после получения метаданных и до захвата блокировки.

## Строковый ключ

```php
use Componenta\CQRS\Lock\Attribute\Lock;

#[Lock('account:{accountId}', ttl: 30.0, blocking: false)]
final readonly class RebuildAccount
{
    public function __construct(public int $accountId) {}
}
```

Пробелы по краям шаблона удаляются в конструкторе атрибута. Подстановки ссылаются на инициализированные нестатические хранимые свойства без PHP property hooks. Null преобразуется в `"null"`, boolean — в `"true"` или `"false"`, скалярные значения и `Stringable` — в строку. Отсутствующие, статические, неинициализированные, hooked-свойства и неподдерживаемые типы приводят к ошибке разрешения ключа.

## Ключ через callback

```php
use Componenta\CQRS\Lock\Attribute\Lock;

#[Lock(self::resourceKey(...), ttl: 30.0, blocking: false)]
final readonly class RebuildProduct
{
    public function __construct(
        public int $tenantId,
        public int $productId,
    ) {}

    private static function resourceKey(self $command): string
    {
        return 'product:' . $command->tenantId . ':' . $command->productId;
    }
}
```

Callback получает исходный объект команды и возвращает непустую строку, содержащую хотя бы один непробельный символ. Он вызывается один раз при каждом входе в middleware, включая вложенный reentrant-вызов. Результат используется без преобразований: пробелы сохраняются, `"0"` допустим, фигурные скобки остаются буквальными. Middleware не подставляет свойства, не добавляет префиксы, не хеширует и не кеширует результат. Для освобождения используется уже вычисленный ресурс, даже если обработчик изменил команду.

В атрибуте можно написать замыкание без захвата переменных:

```php
#[Lock(static function (RebuildProduct $command): string {
    return 'product:' . $command->tenantId . ':' . $command->productId;
})]
final readonly class RebuildProduct
{
    public function __construct(public int $tenantId, public int $productId) {}
}
```

PHP 8.5 поддерживает в аргументах атрибута `static function` и first-class callable функций или статических методов. Стрелочные функции (`fn`, `static fn`) в этих константных выражениях не поддерживаются. Минимум PHP 8.5.13 учитывает исправление области видимости first-class callable в trait. Доступ к приватному методу сохраняется нативно, без восстановления callback по имени.

Тип ключа — `string|Closure`. Строка всегда означает шаблон, даже если совпадает с именем функции. При программном создании метаданных передавайте `Closure`; callable-массивы и invokable-объекты нужно явно преобразовать. Регистрация callback в контейнере и autowiring его параметров не требуются.

## Выполнение и ошибки

| Параметр | По умолчанию | Значение |
|---|---|---|
| `key` | Обязателен | Шаблон либо `Closure(object): string`. |
| `ttl` | `300.0` | Положительная конечная длительность в секундах для Symfony Lock. |
| `blocking` | `true` | Режим захвата, поддерживаемый Symfony store. |

Исключение callback, нативная ошибка типов, неверный тип результата или пустая строка приводят к `Componenta\CQRS\Command\Exception\LockKeyResolutionException` до захвата ресурса и вызова обработчика. Исходный throwable callback сохраняется в `previous`. Сообщение указывает класс команды и контракт результата, не копируя ключ и данные команды. Ошибки создания атрибута остаются ошибками провайдера метаданных.

Одинаковый вычисленный ключ допускает повторный вход внутри одного контекста выполнения. Разные Fibers захватывают блокировку независимо. Ресурс освобождается и после успеха, и после ошибки обработчика. Ошибка освобождения представлена `LockReleaseException`; поля `primaryFailure` и `releaseFailure` сохраняют обе ошибки, если обработчик также завершился неудачно.

Callback может менять общие объекты приложения. Переприсваивание его аргумента не заменяет команду операции. Приложение отвечает за коллизии ключей, tenant/resource namespace и стабильность вычисления.

## Порядок middleware

Приложение определяет порядок. Policy перед блокировкой позволяет отклонить команду без захвата ресурса. Policy внутри блокировки выполняется в защищенном участке.

`ResourceLockMiddleware → RetryMiddleware` удерживает одну блокировку на все попытки. `RetryMiddleware → ResourceLockMiddleware` вычисляет ключ и захватывает ресурс на каждой попытке. Callback не создает дополнительный слой блокировки или повторов.

Middleware не продлевает lease во время выполнения. TTL должен учитывать всю работу внутри блокировки; удаленный lease может истечь раньше завершения обработчика. `blocking` следует поведению Symfony store и не добавляет отдельный deadline.

## Разработка

Проверки: `composer test` и `composer analyse`. [Спецификация](docs/specifications/attribute-callbacks.md) описывает контракт и критерии приемки, а [проверка интеграции](docs/development/integration.md) — версии зависимостей и воспроизводимую настройку окружения.
