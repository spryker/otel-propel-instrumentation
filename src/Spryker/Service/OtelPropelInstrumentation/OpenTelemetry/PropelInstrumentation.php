<?php

namespace Spryker\Service\OtelPropelInstrumentation\OpenTelemetry;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use Propel\Runtime\Connection\StatementInterface;
use Spryker\Shared\Opentelemetry\Instrumentation\CachedInstrumentation;
use Throwable;
use function OpenTelemetry\Instrumentation\hook;

class PropelInstrumentation
{
    /**
     * @var string
     */
    protected const SPAN_NAME = 'propel-execute';

    /**
     * @var string
     */
    protected const METHOD_NAME = 'execute';

    /**
     * @var string
     */
    protected const ATTRIBUTE_QUERY = 'query';

    /**
     * @return void
     */
    public static function register(): void
    {
        hook(
            class: StatementInterface::class,
            function: static::METHOD_NAME,
            pre: function (StatementInterface $statement, array $params): void {
                $context = Context::getCurrent();

                $span = CachedInstrumentation::getCachedInstrumentation()
                    ->tracer()
                    ->spanBuilder(static::SPAN_NAME)
                    ->setParent($context)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(static::ATTRIBUTE_QUERY, $statement->getStatement()->queryString)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: function (StatementInterface $statement, array $params, $response, ?Throwable $exception): void {
                $span = Span::fromContext(Context::getCurrent());

                if ($exception !== null) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR);
                } else {
                    $span->setStatus(StatusCode::STATUS_OK);
                }

                $span->end();
            }
        );
    }
}
