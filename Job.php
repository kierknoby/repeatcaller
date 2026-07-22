<?php

namespace FreePBX\modules\Repeatcaller;

use FreePBX;
use FreePBX\Job\TaskInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class Job implements TaskInterface {
        public static function run(InputInterface $input, OutputInterface $output) {
                try {
                        return FreePBX::Repeatcaller()->runBackgroundMonitor($output);
                } catch (Throwable $e) {
			try {
				FreePBX::Log()->error('repeatcaller job: unhandled scheduler exception: ' . $e->getMessage());
			} catch (Throwable $ignored) {
				error_log('repeatcaller job: unhandled scheduler exception: ' . $e->getMessage());
			}
                        return false;
                }
        }
}
