<?php
// resources/filesystemexample/src/Command/PluginPersistToCsv.php
namespace App\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

use Psr\Log\LoggerInterface;

use App\Entity\FixityCheckEvent;
use App\Service\FixityEventDetailManager;

class PluginPersistToCsv extends ContainerAwareCommand
{
    private $params;

    public function __construct(
        ParameterBagInterface $params = null,
        LoggerInterface $logger = null,
        FixityEventDetailManager $event_detail = null
    ) {
        $this->params = $params;
        $this->event_type = $this->params->get('app.fixity.eventtype.code');

        // This path should be configured in services.yaml but we hard code it here as an example.
        $this->fixity_peristence_csv = '/tmp/riprap_sample_persist_plugin.csv';

        // Set log output path in config/packages/{environment}/monolog.yaml
        $this->logger = $logger;
        $this->event_detail = $event_detail;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('app:riprap:plugin:persist:to:csv')
            ->setDescription('A Riprap plugin for persisting fixity events to a relational database.');

        // phpcs:disable
        $this
            ->addOption('timestamp', null, InputOption::VALUE_REQUIRED, 'ISO 8601 date when the fixity validation event occured.')
            ->addOption('resource_id', null, InputOption::VALUE_REQUIRED, 'Fully qualifid URL of the resource to validate.')
            ->addOption('event_uuid', null, InputOption::VALUE_REQUIRED, 'UUID of the fixity validation event.')
            // ->addOption('event_detail', null, InputOption::VALUE_REQUIRED, 'Fixity check event detail.')
            ->addOption('digest_algorithm', null, InputOption::VALUE_REQUIRED, 'Algorithm used to generate the digest.')
            ->addOption('digest_value', null, InputOption::VALUE_REQUIRED, 'Value of the digest retrieved from the Fedora repository.')
            ->addOption('outcome', null, InputOption::VALUE_REQUIRED, 'Outcome of the event.')
            // Persist plugins are special in that they are executed twice, once to get the last digest for the resource
            // and again to persist the event resulting from comparing that digest with a new one.
            ->addOption('operation', null, InputOption::VALUE_REQUIRED, 'One of "get_last_digest", "get_events", or "persist_new_event".');
        // phpcs:enable           
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!file_exists($this->fixity_peristence_csv)) {
            $csv_headers = array(
                'event_uuid',
                'event_type',
                'resource_id',
                'timestamp',
                'digest_algorithm',
                'digest_value',
                'event_detail',
                'event_outcome',
                'event_outcome_detail_note'
            );
            $header_row = implode(',', $csv_headers);
            file_put_contents($this->fixity_peristence_csv, $header_row . "\n");
        }

        if ($input->getOption('operation') == 'get_last_digest') {
            // The operation must return the latest digest value for the resource. This sloppy
            // demo code does that, but makes a lot of assumptions about the file at
            // $this->fixity_peristence_csv.
            $rows = file($this->fixity_peristence_csv, FILE_IGNORE_NEW_LINES);
            $event_records = array();
            foreach ($rows as $row) {
                $fields = explode(',', $row);
                $event_records[$fields[2]] = $fields[5];
            }
            if (!array_key_exists($input->getOption('resource_id'), $event_records)) {
                $output->write('');
            } else {
                $output->write($event_records[$input->getOption('resource_id')]);
            }
        }

        // Returns a serialized representation of all fixity check events.
        // @todo: Add  offset and limit parameters.
        if ($input->getOption('operation') == 'get_events') {
            $repository = $this->getContainer()->get('doctrine')->getRepository(FixityCheckEvent::class);
            $events = $repository->findFixityCheckEvents(
                $input->getOption('resource_id')
            );
            if (count($events)) {
                $event_entries = array();
                foreach ($events as $event) {
                    $event_array = array();
                    $event_array['event_uuid'] = $event->getEventUuid();
                    $event_array['resource_id'] = $event->getResourceId();
                    $event_array['event_type'] = $event->getEventType();
                    $event_array['timestamp'] = $event->getTimestamp();
                    $event_array['digest_algorithm'] = $event->getDigestAlgorithm();
                    $event_array['digest_value'] = $event->getDigestValue();
                    $event_array['event_detail'] = $event->getEventDetail();
                    $event_array['event_outcome'] = $event->getEventOutcome();
                    $event_array['event_outcome_detail_note'] = $event->getEventOutcomeDetailNote();
                    $event_entries[] = $event_array;
                }
            }
            // $output requires a string.
            $output->write(serialize($event_entries));
        }
        if ($input->getOption('operation') == 'persist_fix_event') {
            $details = $this->event_detail->getDetails();
            $event_details = $this->event_detail->serialize($details);
            $record = array(
                $input->getOption('event_uuid'),
                $this->event_type,
                $input->getOption('resource_id'),
                $input->getOption('timestamp'),
                $input->getOption('digest_algorithm'),
                $input->getOption('digest_value'),
                $event_details['event_detail'],
                $input->getOption('outcome'),
                $event_details['event_outcome_detail_note']
            );
            $record = implode(',', $record);
            file_put_contents($this->fixity_peristence_csv, $record . "\n", FILE_APPEND);
        }
    }
}
