<?php

declare(strict_types=1);

namespace OmarKhaled\Findex\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use OmarKhaled\Findex\Reindexer;

#[Signature('findex:reindex
    {models* : The models to reindex. A short name such as "Product" resolves to App\Models\Product}
    {--chunk=1000 : The number of rows to read at a time}')]
#[Description('Rebuild the Findex search index column of existing rows')]
final class ReindexCommand extends Command
{
    public function handle(Reindexer $reindexer): int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($chunk === false) {
            $this->components->error('The --chunk option must be a positive integer.');

            return self::INVALID;
        }

        $status = self::SUCCESS;

        foreach ((array) $this->argument('models') as $name) {
            $model = $this->resolveModel((string) $name);

            try {
                $total = $reindexer->count($model);
            } catch (InvalidArgumentException $exception) {
                $this->components->error($exception->getMessage());

                $status = self::FAILURE;

                continue;
            }

            $bar = $this->output->createProgressBar($total);
            $bar->start();

            $updated = $reindexer->reindex($model, $chunk, $bar->advance(...));

            $bar->finish();
            $this->newLine(2);

            $this->components->info(sprintf('%s: updated %d of %d rows.', $model, $updated, $total));
        }

        return $status;
    }

    /**
     * Accept a fully qualified class name, or a name relative to App\Models.
     */
    private function resolveModel(string $name): string
    {
        $name = ltrim(str_replace('/', '\\', trim($name)), '\\');

        if (class_exists($name)) {
            return $name;
        }

        return class_exists($candidate = 'App\\Models\\'.$name) ? $candidate : $name;
    }
}
