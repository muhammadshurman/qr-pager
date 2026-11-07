<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Validation\ValidationException;

/**
 * Publishes a failed validation as THIS application's error envelope.
 *
 * Scramble documents a ValidationException with Laravel's DEFAULT body -- `message` plus
 * `errors`, under `application/json` -- which is a body this API has never sent. Every
 * error here leaves through Problem::toResponse() as an RFC 7807 document under
 * `application/problem+json`, so the generated document described a shape and a media
 * type that do not exist, on every route that validates anything.
 *
 * THE BODY SHAPE IS THIS CLASS'S ONLY JOB. It emits under `application/json` like every
 * other generated response, and AppServiceProvider's post-processing step moves every
 * problem-document response to Problem::CONTENT_TYPE afterwards -- one place that decides
 * the media type, rather than each producer repeating it. The attribute's own `mediaType`
 * argument cannot do this: measured on 2026-10-27, Scramble's Response attribute looks the
 * schema up under that media-type key in the response it built from `type:`, which the
 * transformer stores under `application/json`, so a non-default value there publishes
 * `{"type": "string"}` and loses the document entirely.
 *
 * Scramble dispatches exception extensions in reverse registration order and the config
 * list is appended after the built-ins, so this one wins over
 * ValidationExceptionToResponseExtension -- that package's own documented mechanism for
 * overriding a default, not a trick.
 *
 * No reference() on purpose: the built-in registers its response in `components.responses`
 * under the exception's class name, and inlining instead keeps one response object per
 * operation, which is what a per-endpoint description needs.
 */
final class ValidationProblemResponseExtension extends ExceptionToResponseExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType
            && $type->isInstanceOf(ValidationException::class);
    }

    public function toResponse(Type $type): Response
    {
        return Response::make(422)
            ->setDescription('The request did not pass validation.')
            ->setContent(
                'application/json',
                Schema::fromType($this->openApiTransformer->transform(
                    new ObjectType(ValidationProblemDocument::class),
                )),
            );
    }
}
