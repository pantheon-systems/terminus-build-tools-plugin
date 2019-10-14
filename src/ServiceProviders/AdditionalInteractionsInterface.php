<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

interface AdditionalInteractionsInterface {

  /**
   * Perform interactions to collect provider-specific options.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   */
  public function addInteractions(InputInterface $input, OutputInterface $output);

}