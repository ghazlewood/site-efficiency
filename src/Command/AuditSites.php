<?php

namespace SiteEfficiency\Command;

use DateTime;
use DateTimeZone;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;
use SiteEfficiency\Profile\Profile;
use SiteEfficiency\GoogleAnalytics\Api as GaApi;
use SiteEfficiency\Sumologic\Api as SumoApi;

class AuditSites extends Command {

  protected $output = NULL;
  protected $start = NULL;
  protected $end = NULL;
  protected $profile;
  protected $format = [];
  protected $limit = 10;
  protected $range = 7;
  protected $sumoApi = NULL;

  /**
   * @inheritdoc
   */
  protected function configure() {
    $this
      ->setName('audit:sites')
      ->setDescription('Audits your Google Analytics pages views vs drupal requests for a time frame.')
      ->addOption(
        'profile',
        'p',
        InputOption::VALUE_REQUIRED,
        'The profile to use.'
      )
      ->addOption(
        'limit',
        'l',
        InputOption::VALUE_REQUIRED,
        'The number of hostnames to have in the final report.',
        10
      )
      ->addOption(
        'range',
        'r',
        InputOption::VALUE_REQUIRED,
        'The report range in days. The default end date is at least 24 hours in the past. You can also use "lastmonth" to do the last calendar month.',
        7
      )
      ->addOption(
        'format',
        'f',
        InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
        'Desired output format.',
        ['html']
      )
    ;
  }

  /**
   * @inheritdoc
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->timerStart();

    $this->profile = new Profile($input->getOption('profile'));
    $this->limit = (int) $input->getOption('limit');
    $this->format = $input->getOption('format');
    $this->range = $input->getOption('range');
    $this->output = $output;

    $io = new SymfonyStyle($input, $output);
    $io->title('Site efficiency report');

    // Connect to Google, and find out the profile's timezone.
    $gaApi = new GaApi($this->profile, $output);
    $gaApi->getProfileTimezone();

    // Work out the date range for the report. Note that there is a 24-48 delay
    // for Google Analytics statistics, so ensure we go back at least an entire
    // day.
    // @see https://support.google.com/analytics/answer/1070983?hl=en
    $start = new DateTime('now', new DateTimeZone($this->profile->getTimezone()));
    $start->setTime(0,0,0);
    $start->modify('-1 day');
    if ($this->range === 'lastmonth') {
      $start->modify('first day of this month');
      $start->setTime(0,0,0);
      $end = clone($start);
      $end->modify('-1 second');
      $start->modify('-1 month');
    }
    else {
      $end = clone($start);
      $end->modify('-1 second');
      $start->modify("-{$this->range} days");
    }

    $io->text("Report range: {$start->format(DateTime::ATOM)} - {$end->format(DateTime::ATOM)}.");

    // Get all zone data including pagination.
    $resultsGa = $gaApi->getTopHostnamesInGa($this->limit * 4, $start, $end);

    // Query Sumologic.
    $this->sumoApi = new SumoApi($this->profile, $output);
    $resultsSumo = $this->sumoApi->getPhpTimeForHostnames($this->limit * 4, $start, $end);

    // Combine the arrays, key on domain.
    $resultsCombined = [];
    foreach ($resultsSumo['sites'] as $resultSumo) {
      $found = FALSE;
      foreach ($resultsGa['sites'] as $resultGa) {
        if ($resultSumo['domain'] === $resultGa['domain']) {
          $found = TRUE;
          $resultsCombined[] = $resultSumo + [
            'pageviews' => $resultGa['pageviews']
          ];
          break;
        }
      }

      // Missing GA stats for the domain. This could be indicative of having the
      // tracker mis-configured.
      if (!$found) {
        $resultsCombined[] = $resultSumo + [
          'pageviews' => '-1'
        ];
      }
    }

    // Slice it down.
    $resultsCombined = array_slice($resultsCombined, 0, $this->limit);
    $variables = [
      'meta' => [
        'limit' => $this->limit,
        'site_name' => $this->profile->getSiteName(),
        'site_environment' => $this->profile->getSiteEnvironment(),
        'site_realm' => $this->profile->getSiteRealm(),
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
        'total_pageviews' => $resultsGa['total']['ga:pageviews'],
        'timezone' => $this->profile->getTimezone(),
      ],
      'sites' => $resultsCombined,
    ];

    $seconds = $this->timerEnd();
    $io->text("Execution time: $seconds seconds.");
    $variables['meta']['execution time'] = $seconds;

    foreach ($this->format as $format) {
      $filename = $this->profile->getSiteShortName() . '-sites';
      switch ($format) {
        case 'json':
          $json = json_encode($variables, JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
          file_put_contents("./reports/{$filename}.json", $json);
          $io->success("JSON file written to ./reports/{$filename}.json");
          break;

        case 'html':
          $this->writeHTMLReport($io, $variables, "./reports/{$filename}.html");
          $io->success("HTML file written to ./reports/{$filename}.html");
          break;

        case 'yaml':
        default:
          $yaml = Yaml::dump($variables, 3);
          file_put_contents("./reports/{$filename}.yml", $yaml);
          $io->success("YAML file written to ./reports/{$filename}.yml");
          break;
      }
    }
  }

  /**
   * Gracefully stop the application. Killing off any long running Sumologic
   * queries.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   */
  public function stopCommand(OutputInterface $output) {
    $output->writeln('Stopping process gracefully.');
    if ($this->sumoApi) {
      $this->sumoApi->deleteSearchJob();
    }
  }


  /**
   * Convert the results into HTML.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   The output style.
   * @param array $variables
   *   Variables to output in the template.
   * @param string $filepath
   *   The path to the HTML file.
   */
  protected function writeHTMLReport(SymfonyStyle $io, array $variables = [], $filepath) {
    $loader = new \Twig_Loader_Filesystem(__DIR__ . '/../../templates');
    $twig = new \Twig_Environment($loader, array(
      'cache' => sys_get_temp_dir() . '/site-efficiency/cache',
      'auto_reload' => TRUE,
    ));
    $template = $twig->load('sites.html.twig');
    $contents = $template->render([
      'meta' => $variables['meta'],
      'sites' => $variables['sites'],
    ]);

    if (is_file($filepath) && !is_writable($filepath)) {
      throw new \RuntimeException("Cannot overwrite file: $filepath");
    }

    file_put_contents($filepath, $contents);
  }

  protected function timerStart() {
    $this->start = microtime(true);
  }

  protected function timerEnd() {
    $this->end = microtime(true);
    return (int) ($this->end - $this->start);
  }

}
