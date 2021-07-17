<?php declare(strict_types=1);

namespace App\Controller;

use App\Entity\Movie;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use Slim\Interfaces\RouteCollectorInterface;
use Twig\Environment;
use function App\Support\__invoke;

class HomeController
{
    public function __construct(
        private RouteCollectorInterface $routeCollector,
        private Environment $twig,
        private EntityManagerInterface $em
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $data = $this->twig->render('home/index.html.twig', [
                'trailers' => $this->fetchData(),
                'class' => __CLASS__,
                'method' => __FUNCTION__,
            ]);
        } catch (\Exception $e) {
            throw new HttpBadRequestException($request, $e->getMessage(), $e);
        }

        $response->getBody()->write($data);

        return $response;
    }

/**
 * @param ServerRequestInterface $request
 * @param ResponseInterface $response
 * @return ResponseInterface
 * @throws HttpNotFoundException
 */
public
function detail(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
{
    $id = $request->getAttribute('id');

    $trailer = $this->getTrailer($id);

    if (empty($trailer)) {
        throw new HttpNotFoundException($request);
    }

    $data = $this->twig->render('home/detail.html.twig', [
        'trailer' => $trailer,
    ]);

    $response->getBody()->write($data);

    return $response;
}

protected
function fetchData(): Collection
{
    $data = $this->em->getRepository(Movie::class)
        ->findAll();

    return new ArrayCollection($data);
}

/**
 * @param null $id
 * @return Movie|null
 */
private
function getTrailer($id = null): ?Movie
{
    return $this->em->getRepository(Movie::class)
        ->findOneBy(['id' => $id]);
}
}
