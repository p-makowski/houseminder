<?php

namespace App\Actions;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class DummyAction
{
    /** @return array<int, array{name: string, description: string, interval_value: int|float, interval_unit: string}> */
    public function __invoke(string $applianceName, string $applianceModel, string $typeName): array
    {
        $a = 15;

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


        return [];
    }
}
