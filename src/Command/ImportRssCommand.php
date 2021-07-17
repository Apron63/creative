<?php

namespace App\Command;

use App\Entity\Movie;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ImportRssCommand extends Command
{
    protected static $defaultName = 'import:rss';
    public string $defaultUrl = 'https://trailers.apple.com/trailers/home/rss/newtrailers.rss';
    public string $tableName = 'MOVIE';
    private EntityManagerInterface $em;
    private \XMLReader $reader;

    /**
     * ImportCommand constructor.
     *
     * @param EntityManagerInterface $em
     */
    public function __construct(EntityManagerInterface $em)
    {
        parent::__construct();
        $this->em = $em;
        $this->em->getConnection()->getConfiguration()->setSQLLogger(null);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Import RSS')
            ->setHelp('Import RSS from url');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     *
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Start...');

        if (null === $this->prepareDb($this->tableName)) {
            $output->writeln('Cannot prepare DB table...');

            return Command::FAILURE;
        }

        if (null === $content = $this->getContent($this->defaultUrl)) {
            $output->writeln('Cannot load RSS...');

            return Command::FAILURE;
        }

        $trailers = $this->parseContent($content);

        $cnt = count($trailers);
        $elements = $cnt - 10 > 0 ? $cnt - 10 : 0;
        for ($i = $cnt - 1; $i >= $elements; --$i) {
            $this->saveToDb($trailers[$i]);
        }

        $output->writeln('Done.');

        return Command::SUCCESS;
    }

    /**
     * @param string $tableName
     *
     * @return bool|null
     */
    private function prepareDb(string $tableName): ?bool
    {
        if (empty($tableName)) {
            return null;
        }
        try {
            $this->em->getConnection()->executeQuery("TRUNCATE TABLE {$tableName}");
        } catch (\Exception $e) {
            return null;
        }

        return true;
    }

    /**
     * @param $url
     *
     * @return string|null
     */
    private function getContent($url): ?string
    {
        $headers = [
            'Connection: keep-alive',
            'Pragma: no-cache',
            'Cache-Control: no-cache',
            'Accept: */*',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/87.0.4280.67 Safari/537.36 Edg/87.0.664.52',
            'Accept-Encoding: gzip, deflate',
            'Accept-Language: ru,en;q=0.9,en-GB;q=0.8,en-US;q=0.7',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_COOKIEFILE, 'cookie.txt');
        curl_setopt($ch, CURLOPT_COOKIEJAR, 'cookie.txt');
        curl_setopt($ch, CURLOPT_POST, false);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $html = curl_exec($ch);
        $ch_info = curl_getinfo($ch);

        if (false === $html) {
            return null;
        }

        $result = mb_substr($html, $ch_info['header_size']);
        if (empty($result)) {
            return null;
        }

        curl_close($ch);

        return $result;
    }

    /**
     * @param string $content
     *
     * @return array
     */
    private function parseContent(string $content): array
    {
        $this->reader = new \XMLReader();
        $this->reader->XML($content);
        $this->reader->moveToAttribute('item');
        $trailers = [];

        while ($this->reader->read()) {
            if (($this->reader->nodeType === \XMLReader::ELEMENT) && $this->reader->localName === 'item') {
                /** @var \DOMElement $dom */
                $dom = $this->reader->expand();

                if ($dom === false) {
                    continue;
                }
                $data = $this->parseNode($dom);
                if (!empty($data)) {
                    $trailers[] = $data;
                }
            }
        }

        return $trailers;
    }

    /**
     * @param \DOMElement $dom
     *
     * @return array
     */
    private function parseNode(\DOMElement $dom): array
    {
        $children = $dom->childNodes;
        $data = [];

        foreach ($children as $child) {
            if ($child->nodeName === '#text') {
                continue;
            }

            if ($child->nodeName === 'content:encoded') {
                $image = [];
                preg_match('/src="([^"]*)"/i', $child->nodeValue, $image);
                $data['image'] = $image[1];
            } else {
                $data[$child->nodeName] = $child->nodeValue;
            }
        }

        return $data;
    }

    /**
     * @param array $data
     *
     * @throws \Exception
     */
    private function saveToDb(array $data): void
    {
        $movie = new Movie();
        $movie
            ->setTitle($data['title'])
            ->setLink($data['link'])
            ->setDescription($data['description'])
            ->setPubDate(new \DateTime($data['pubDate']))
            ->setImage(($data['image']));

        $this->em->persist($movie);
        $this->em->flush();
    }
}
