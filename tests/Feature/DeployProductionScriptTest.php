<?php

namespace Tests\Feature;

use Tests\TestCase;

class DeployProductionScriptTest extends TestCase
{
    public function test_script_pins_target_and_enters_maintenance_before_changing_checkout(): void
    {
        $script = file_get_contents(base_path('scripts/deploy-production.sh'));

        $this->assertIsString($script);
        $this->assertStringContainsString('TARGET_SHA="${1:-}"', $script);
        $this->assertStringContainsString('git merge --ff-only "$TARGET_SHA"', $script);
        $this->assertStringNotContainsString('git pull', $script);
        $this->assertLessThan(
            strpos($script, 'git merge --ff-only "$TARGET_SHA"'),
            strpos($script, '"$PHP_BIN" artisan down'),
        );
        $this->assertLessThan(
            strpos($script, '"$PHP_BIN" "$DEPLOY_COMPOSER_PATH" install'),
            strpos($script, '"$PHP_BIN" artisan down'),
        );
    }

    public function test_script_treats_current_target_as_a_no_op_before_maintenance_and_dependencies(): void
    {
        $script = file_get_contents(base_path('scripts/deploy-production.sh'));
        $noOp = strpos($script, 'Already up to date');

        $this->assertNotFalse($noOp);
        $this->assertLessThan(strpos($script, '"$PHP_BIN" artisan down'), $noOp);
        $this->assertLessThan(strpos($script, '"$PHP_BIN" "$DEPLOY_COMPOSER_PATH" install'), $noOp);
        $this->assertLessThan(strpos($script, 'npm ci'), $noOp);
    }

    public function test_script_requires_php85_and_a_compatible_composer_script(): void
    {
        $script = file_get_contents(base_path('scripts/deploy-production.sh'));
        preg_match_all('/^.*artisan.*$/m', $script, $artisanLines);

        $this->assertStringContainsString('PHP_BIN="php8.5"', $script);
        $this->assertNotEmpty($artisanLines[0]);
        foreach ($artisanLines[0] as $line) {
            $this->assertStringContainsString('"$PHP_BIN" artisan', $line);
        }
        $this->assertStringContainsString('DEPLOY_COMPOSER_PATH', $script);
        $this->assertStringContainsString('Composer version', $script);
        $this->assertStringContainsString('"$PHP_BIN" "$DEPLOY_COMPOSER_PATH" --version', $script);
    }

    public function test_script_keeps_application_down_after_any_post_maintenance_failure(): void
    {
        $script = file_get_contents(base_path('scripts/deploy-production.sh'));
        $handlerStart = strpos($script, 'handle_failure()');
        $handlerEnd = strpos($script, 'trap handle_failure EXIT');
        $handler = substr($script, $handlerStart, $handlerEnd - $handlerStart);

        $this->assertStringContainsString('Application remains in maintenance', $handler);
        $this->assertStringNotContainsString('artisan up', $handler);
        $this->assertStringContainsString('DEPLOY_PAUSE_WORKERS_HOOK', $script);
        $this->assertStringContainsString('DEPLOY_RESUME_WORKERS_HOOK', $script);
    }

    public function test_queue_restart_is_graceful_and_result_persistence_follows_process_completion(): void
    {
        $script = file_get_contents(base_path('scripts/deploy-production.sh'));
        $service = file_get_contents(app_path('Services/System/DeployService.php'));
        $worker = file_get_contents(base_path('vendor/laravel/framework/src/Illuminate/Queue/Worker.php'));

        $this->assertStringContainsString('artisan queue:restart', $script);
        $this->assertStringContainsString('$this->runJob($job, $connectionName, $options);', $worker);
        $this->assertGreaterThan(
            strpos($worker, '$this->runJob($job, $connectionName, $options);'),
            strpos($worker, '[$status, $reason] = $this->stopIfNecessary('),
        );
        $this->assertGreaterThan(
            strpos($service, "->run(['bash', \$scriptPath, \$target])"),
            strpos($service, "'status' => \$successful ? DeployRun::STATUS_SUCCEEDED"),
        );
    }

    public function test_production_contract_documents_dedicated_worker_and_safe_retry_window(): void
    {
        $environment = file_get_contents(base_path('.env.example'));
        $documentation = file_get_contents(base_path('docs/deploy-production.md'));

        $this->assertStringContainsString('DB_QUEUE_RETRY_AFTER=1200', $environment);
        $this->assertStringContainsString('DEPLOY_COMPOSER_PATH=', $environment);
        $this->assertStringContainsString('--queue=deploys ', $documentation);
        $this->assertStringNotContainsString('--queue=deploys,default', $documentation);
        $this->assertStringContainsString('php8.5', $documentation);
        $this->assertStringContainsString('manual y supervisado', $documentation);
    }
}
