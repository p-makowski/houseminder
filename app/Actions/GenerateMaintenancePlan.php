<?php

declare(strict_types=1);

namespace App\Actions;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class GenerateMaintenancePlan
{
    public function __invoke(string $applianceName, string $applianceModel, string $typeName): array
    {
        $applianceName = mb_substr($applianceName, 0, 255);
        $applianceModel = mb_substr($applianceModel, 0, 255);
        $typeName      = mb_substr($typeName, 0, 255);

        $schema = new ObjectSchema(
            name: 'maintenance_plan',
            description: 'AI-generated maintenance tasks for a household appliance',
            properties: [
                new ArraySchema(
                    name: 'tasks',
                    description: 'List of suggested maintenance tasks',
                    items: new ObjectSchema(
                        name: 'task',
                        description: 'A single maintenance task',
                        properties: [
                            new StringSchema('name', 'Task name, e.g. "Clean filter"'),
                            new StringSchema('description', 'What to do and why'),
                            new NumberSchema('interval_value', 'Positive integer interval, e.g. 6'),
                            new StringSchema('interval_unit', 'Unit: days, weeks, months, or years'),
                        ],
                        requiredFields: ['name', 'description', 'interval_value', 'interval_unit'],
                    ),
                ),
            ],
            requiredFields: ['tasks'],
        );

        $systemMessage = (new SystemMessage(
            'You are a home appliance maintenance expert. '
            . 'Suggest 3 to 6 practical, calendar-based maintenance tasks for the given appliance. '
            . 'interval_unit MUST be one of: days, weeks, months, years. '
            . 'Never return hours or km. '
            . 'Return structured JSON only.'
        ))->withProviderOptions(['cacheType' => 'ephemeral', 'cacheTtl' => '5m']);

        $response = Prism::structured()
            ->withSchema($schema)
            ->using(Provider::Anthropic, 'claude-sonnet-4-5')
            ->withSystemPrompt($systemMessage)
            ->withMessages([
                new UserMessage("Suggest maintenance tasks for: {$applianceName}, model {$applianceModel}, type {$typeName}."),
            ])
            ->usingTemperature(0.3)
            ->withMaxTokens(1024)
            ->withClientOptions(['timeout' => 30])
            ->withClientRetry(2, 500)
            ->asStructured();

        return $response->structured['tasks'] ?? [];
    }
}
