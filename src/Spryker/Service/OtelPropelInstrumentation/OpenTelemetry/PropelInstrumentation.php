<?php

namespace Spryker\Service\OtelPropelInstrumentation\OpenTelemetry;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Throwable;
use function OpenTelemetry\Instrumentation\hook;

class PropelInstrumentation
{
    /**
     * @var string
     */
    protected const START_TIME = 'start_time';

    /**
     * @var string
     */
    protected const ATTRIBUTE_DB_NAME = 'db.system';

    /**
     * @var string
     */
    protected const ATTRIBUTE_OPERATION = 'db.operation';

    /**
     * @var string
     */
    protected const ATTRIBUTE_DURATION = 'duration';

    /**
     * @var string
     */
    protected const SPAN_NAME_CREATE = 'propel-create';

    /**
     * @var string
     */
    protected const SPAN_NAME_UPDATE = 'propel-update';

    /**
     * @var string
     */
    protected const SPAN_NAME_DELETE = 'propel-delete';

    /**
     * @return void
     */
    public static function register(): void
    {
        $operations = [
            'doInsert' => static::SPAN_NAME_CREATE,
            'doUpdate' => static::SPAN_NAME_UPDATE,
            'doDelete' => static::SPAN_NAME_DELETE,
        ];

        foreach ($operations as $operation => $spanName) {
            static::registerHook($operation, $spanName);
        }
    }

    /**
     * @param string $functionName
     * @param string $spanName
     *
     * @return void
     */
    protected static function registerHook(string $functionName, string $spanName): void
    {
        $instrumentation = CachedInstrumentation::getCachedInstrumentation();

        hook(
            class: ConnectionInterface::class,
            function: $functionName,
            pre: function (ConnectionInterface $connection, array $params) use ($instrumentation, $spanName): void {
                $startTime = microtime(true);

                $span = $instrumentation->tracer()
                    ->spanBuilder($spanName)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(static::START_TIME, $startTime)
                    ->setAttribute(static::ATTRIBUTE_DB_NAME, $connection->getConfiguration()->getConnectionName())
                    ->setAttribute(static::ATTRIBUTE_OPERATION, $functionName);

                $span->startSpan()->activate();
            },
            post: function ($instance, array $params, $response, ?Throwable $exception): void {
                $span = Span::fromContext(Context::getCurrent());

                if ($exception !== null) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR);
                } else {
                    $span->setStatus(StatusCode::STATUS_OK);
                }

                $endTime = microtime(true);
                $duration = $endTime - $span->getAttribute(static::START_TIME);
                $span->setAttribute(static::START_TIME, null);
                $span->setAttribute(static::ATTRIBUTE_DURATION, $duration);

                $span->end();
            }
        );
    }
}
