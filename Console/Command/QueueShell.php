<?php
App::uses('Folder', 'Utility');

/**
 * @author MGriesbach@gmail.com
 * @package QueuePlugin
 * @subpackage QueuePlugin.Shells
 * @license http://www.opensource.org/licenses/mit-license.php The MIT License
 * @link http://github.com/MSeven/cakephp_queue
 */
class QueueShell extends AppShell {
	public $uses = array(
		'Queue.QueuedTask'
	);
	/**
	 * Codecomplete Hint
	 *
	 * @var QueuedTask
	 */
	public $QueuedTask;
	
	protected $taskConf;

	/**
	 * Overwrite shell initialize to dynamically load all Queue Related Tasks.
	 */
	public function initialize() {
		$this->_loadModels();
		
		$x = App::objects('Queue.Task'); //'Console/Command/Task'
		//$x = App::path('Task', 'Queue');
		
		$paths = App::path('Console/Command/Task');
		foreach ($paths as $path) {
			$Folder = new Folder($path);
			$this->tasks = array_merge($this->tasks, $Folder->find('Queue.*\.php'));
		}
		
		$plugins = App::objects('plugin');
		foreach ($plugins as $plugin) {
			$pluginPaths = App::path('Console/Command/Task', $plugin);
			foreach ($pluginPaths as $pluginPath) {
				$Folder = new Folder($pluginPath);
				$res = $Folder->find('Queue.*Task\.php');
				foreach ($res as &$r) {
					$r = $plugin . '.' . basename($r, 'Task.php');
				}
				$this->tasks = array_merge($this->tasks, $res);
			}
			
		}
		
		//$pluginPaths = App::path('Console/Command/Task', 'Queue');
		//die(returns($this->tasks));
		
		//Config can be overwritten via local app config.
		Configure::load('Queue.queue');
		
		$conf = (array)Configure::read('queue');
		//merge with default configuration vars.
		Configure::write('queue', array_merge(array(
			'sleeptime' => 10,
			'gcprop' => 10,
			'defaultworkertimeout' => 120,
			'defaultworkerretries' => 4,
			'workermaxruntime' => 0,
			'cleanuptimeout' => 2000,
			'exitwhennothingtodo' => false,
			'log' => false,
		), $conf));
		
	}

	/**
	 * Output some basic usage Info.
	 */
	public function help() {
		$this->out('CakePHP Queue Plugin:');
		$this->hr();
		$this->out('Usage:');
		$this->out('	cake queue help');
		$this->out('		-> Display this Help message');
		$this->out('	cake queue add <taskname>');
		$this->out('		-> Try to call the cli add() function on a task');
		$this->out('		-> tasks may or may not provide this functionality.');
		$this->out('	cake queue runworker');
		$this->out('		-> run a queue worker, which will look for a pending task it can execute.');
		$this->out('		-> the worker will always try to find jobs matching its installed Tasks');
		$this->out('		-> see "Available Tasks" below.');
		$this->out('	cake queue stats');
		$this->out('		-> Display some general Statistics.');
		$this->out('	cake queue clean');
		$this->out('		-> Manually call cleanup function to delete task data of completed tasks.');
		$this->out('Notes:');
		$this->out('	<taskname> may either be the complete classname (eg. queue_example)');
		$this->out('	or the shorthand without the leading "queue_" (eg. example)');
		$this->out('Available Tasks:');
		foreach ($this->taskNames as $loadedTask) {
			$this->out('	->' . $loadedTask);
		}
	}

	/**
	 * Look for a Queue Task of hte passed name and try to call add() on it.
	 * A QueueTask may provide an add function to enable the user to create new jobs via commandline.
	 *
	 */
	public function add() {
		if (count($this->args) < 1) {
			$this->out('Please call like this:');
			$this->out('       cake queue add <taskname>');
		} else {
			
			//$this->QueueEmail->add();
			//echo returns($this->args);
			//die(returns($this->taskNames));
			
			
			if (in_array($this->args[0].'', $this->taskNames)) {
				$this->{$this->args[0]}->add();
			} elseif (in_array('Queue' . $this->args[0] . '', $this->taskNames)) {
				$this->{'Queue' . $this->args[0]}->add();
			} else {
				$this->out('Error: Task not Found: ' . $this->args[0]);
				$this->out('Available Tasks:');
				foreach ($this->taskNames as $loadedTask) {
					$this->out(' * ' . $loadedTask);
				}
			}
		}
	}

	/**
	 * Run a QueueWorker loop.
	 * Runs a Queue Worker process which will try to find unassigned jobs in the queue
	 * which it may run and try to fetch and execute them.
	 */
	public function runworker() {
		// Enable Garbage Collector (PHP >= 5.3)
		if (function_exists('gc_enable')) {
		    gc_enable();
		}
		$exit = false;
		$starttime = time();
		$group = null;
		if (isset($this->params['group']) && !empty($this->params['group'])) {
			$group = $this->params['group'];
		}		
		while (!$exit) {
			$this->_log('runworker');
			$this->out('Looking for Job....');
			$data = $this->QueuedTask->requestJob($this->_getTaskConf(), $group);
			if ($this->QueuedTask->exit === true) {
				$exit = true;
			} else {
				if ($data !== false) {
					$this->out('Running Job of type "' . $data['jobtype'] . '"');
					$taskname = 'Queue' . strtolower($data['jobtype']);
					$return = $this->{$taskname}->run(unserialize($data['data']));
					if ($return) {
						$this->QueuedTask->markJobDone($data['id']);
						$this->out('Job Finished.');
					} else {
						$failureMessage = null;
						if (isset($this->{$taskname}->failureMessage) && !empty($this->{$taskname}->failureMessage)) {
							$failureMessage = $this->{$taskname}->failureMessage;
						}
						$this->QueuedTask->markJobFailed($data['id'], $failureMessage);
						$this->out('Job did not finish, requeued.');
					}
				} elseif (Configure::read('queue.exitwhennothingtodo')) {
					$this->out('nothing to do, exiting.');
					$exit = true;
				} else {
					$this->out('nothing to do, sleeping.');
					sleep(Configure::read('queue.sleeptime'));
				}
				
				// check if we are over the maximum runtime and end processing if so.
				if (Configure::read('queue.workermaxruntime') != 0 && (time() - $starttime) >= Configure::read('queue.workermaxruntime')) {
					$exit = true;
					$this->out('Reached runtime of ' . (time() - $starttime) . ' Seconds (Max ' . Configure::read('queue.workermaxruntime') . '), terminating.');
				}
				if ($exit || rand(0, 100) > (100 - Configure::read('queue.gcprop'))) {
					$this->out('Performing Old job cleanup.');
					$this->QueuedTask->cleanOldJobs();
				}
				$this->hr();
			}
		}
	}

	/**
	 * Manually trigger a Finished job cleanup.
	 * @return null
	 */
	public function clean() {
		$this->out('Deleting old jobs, that have finished before ' . date('Y-m-d H:i:s', time() - Configure::read('queue.cleanuptimeout')));
		$this->QueuedTask->cleanOldJobs();
	}

	/**
	 * Display Some statistics about Finished Jobs.
	 * @return null
	 */
	public function stats() {
		$this->out('Jobs currenty in the Queue:');
		
		$types = $this->QueuedTask->getTypes();
		
		foreach ($types as $type) {
			$this->out("      " . str_pad($type, 20, ' ', STR_PAD_RIGHT) . ": " . $this->QueuedTask->getLength($type));
		}
		$this->hr();
		$this->out('Total unfinished Jobs      : ' . $this->QueuedTask->getLength());
		$this->hr();
		$this->out('Finished Job Statistics:');
		$data = $this->QueuedTask->getStats();
		foreach ($data as $item) {
			$this->out(" " . $item['QueuedTask']['jobtype'] . ": ");
			$this->out("   Finished Jobs in Database: " . $item[0]['num']);
			$this->out("   Average Job existence    : " . $item[0]['alltime'] . 's');
			$this->out("   Average Execution delay  : " . $item[0]['fetchdelay'] . 's');
			$this->out("   Average Execution time   : " . $item[0]['runtime'] . 's');
		}
	}
	
	/**
	 * set up tables
	 */
	public function install() {
		
	}
	
	/**
	 * remove table and kill workers
	 */
	public function uninstall() {
		
	}
	
	
	/**
	 * timestamped log
	 * 2011-10-09 ms
	 */
	protected function _log($type) {
		# log?
		if (Configure::read('queue.log')) {
			$folder = LOGS.'queue';
			if (!file_exists($folder)) {
				mkdir($folder, 0755, true);
			}
			$file = $folder . DS . $type.'.txt';
			file_put_contents($file, date(FORMAT_DB_DATETIME));
		}
	}
	

	/**
	 * Returns a List of available QueueTasks and their individual configurations.
	 * @return array
	 */
	protected function _getTaskConf() {
		if (!is_array($this->taskConf)) {
			$this->taskConf = array();
			foreach ($this->tasks as $task) {
				$this->taskConf[$task]['name'] = $task;
				if (property_exists($this->{$task}, 'timeout')) {
					$this->taskConf[$task]['timeout'] = $this->{$task}->timeout;
				} else {
					$this->taskConf[$task]['timeout'] = Configure::read('queue.defaultworkertimeout');
				}
				if (property_exists($this->{$task}, 'retries')) {
					$this->taskConf[$task]['retries'] = $this->{$task}->retries;
				} else {
					$this->taskConf[$task]['retries'] = Configure::read('queue.defaultworkerretries');
				}
				if (property_exists($this->{$task}, 'rate')) {
					$this->taskConf[$task]['rate'] = $this->{$task}->rate;
				}
			}
		}
		return $this->taskConf;
	}
}