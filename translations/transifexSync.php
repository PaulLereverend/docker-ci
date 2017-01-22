<?php

declare(strict_types=1);

$logPath = '/var/log/cronie/';
$dataPath = '/srv/cronie-data';

if (gethostname() !== 'transifex-sync') {
	$logPath = __DIR__ . '/log/';
	$dataPath = __DIR__ . '/data/';
}

class ResultInfo {
	/** @var DateTime */
	public $startDate;
	/** @var DateTime */
	public $endDate;
	/** @var string */
	public $errorMessage = '';

	public function getJson(): string {
		$data = [
			'start' => $this->startDate->format(\DateTime::ISO8601),
			'end' => $this->endDate->format(\DateTime::ISO8601),
			'error' => $this->errorMessage
		];
		return json_encode($data);
	}
}

function runJob(string $name, string $arguments, string $dataPath, string $logPath): ResultInfo {

	if ($name === 'server') {
		$imageName = '';
	} else {
		$imageName = '-' . $name;
	}

	$result = new ResultInfo();

	$result->startDate = new DateTime();

	print('  Pulling container' . PHP_EOL);
	shell_exec('docker pull nextcloudci/translations' . $imageName);
	print('  Running container' . PHP_EOL);
	exec('docker run -v ' . $dataPath . 'transifexrc:/root/.transifexrc -v ' . $dataPath . 'gpg:/gpg -v ' . $dataPath . 'ssh/id_rsa:/root/.ssh/id_rsa --rm -i nextcloudci/translations' . $imageName . ' ' . $arguments . ' 2>&1', $output, $returnValue);


	$result->endDate = new DateTime();

	$output = implode(PHP_EOL, $output);

	if ($returnValue !== 0) {
		$result->errorMessage = $output;
	}

	$fileName = $name . '-' . str_replace(' ', '-', $arguments) . '-' .$result->startDate->format('Y-m-d.H-i-s');
	file_put_contents($logPath . $fileName . '.log', $output);
	file_put_contents($logPath . $fileName . '.json', $result->getJson());

	return $result;
}

$data = json_decode(file_get_contents(__DIR__ . '/config.json'), true);

if(is_null($data)) {
	print('Cannot decode JSON from config.json.'. PHP_EOL);
	die(1);
}

if(!file_exists($dataPath . '/transifexrc')) {
	print('"transifexrc" does not exist in "' . $dataPath . '".' . PHP_EOL);
	die(1);
}
if(!file_exists($dataPath . '/gpg')) {
	print('"gpg" does not exist in "' . $dataPath . '".' . PHP_EOL);
	die(1);
}
if(!file_exists($dataPath . '/ssh/id_rsa')) {
	print('"ssh/id_rsa" does not exist in "' . $dataPath . '".' . PHP_EOL);
	die(1);
}
if(substr($logPath, 0, 1) !== '/' || substr($logPath, strlen($logPath) - 1, 1) !== '/') {
	print('$logPath (' . $logPath . ') needs to start and end with a "/"' . PHP_EOL);
	die(1);
}
if(substr($dataPath, 0, 1) !== '/' || substr($dataPath, strlen($dataPath) - 1, 1) !== '/') {
	print('$dataPath (' . $dataPath . ') needs to start and end with a "/"' . PHP_EOL);
	die(1);
}
if(!file_exists($dataPath) || !file_exists($dataPath)) {
	print('$dataPath (' . $dataPath . ') and $logPath (' . $logPath . ') need to exist' . PHP_EOL);
	die(1);
}
if(!is_writable($logPath)) {
	print('$logPath (' . $logPath . ') need to be writable' . PHP_EOL);
	die(1);
}

$jobs = $data['jobs'];

foreach ($jobs as $job) {
	if(!isset($job['name']) || !isset($job['arguments'])) {
		print('Job does not have a name or arguments list.');
		print_r($job);
	}
	$name = $job['name'];
	$argumentsList = $job['arguments'];

	print('Job: ' . $name . PHP_EOL);

	foreach ($argumentsList as $arguments) {
		print('  Arguments: ' . $arguments . PHP_EOL);

		if ($arguments !== 'password_policy') {
			continue;
		}
		$result = runJob($name, $arguments, $dataPath, $logPath);

		// try to run it a second time (maybe a github pull issue)
		if ($result->errorMessage !== '') {
			print('  Second run' . PHP_EOL);
			$result = runJob($name, $arguments, $dataPath, $logPath);
		}
	}
}
