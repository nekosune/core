<?php
declare(strict_types=1);

namespace LotGD\Core\Models;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;

use LotGD\Core\Exceptions\ArgumentException;
use LotGD\Core\Tools\Model\Creator;
use LotGD\Core\Tools\Model\Deletor;
use LotGD\Core\Tools\Model\SceneBasics;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * A scene is a location within the game, such as the Village or the Tavern. Designed
 * to be a kind of "template" for generating the specific location information for
 * a specific user, which then becomes a Viewpoint.
 * @Entity
 * @Table(name="scenes")
 */
class Scene implements CreateableInterface, SceneConnectable
{
    use Creator;
    use Deletor;
    use SceneBasics;

    /** @Id @Column(type="string", length=36, unique=True, name="id", options={"fixed" = true}) */
    protected $id;

    /**
     * @OneToMany(targetEntity="SceneConnectionGroup", mappedBy="scene", cascade={"persist", "remove"})
     */
    private $connectionGroups = null;

    /**
     * @OneToMany(targetEntity="SceneConnection", mappedBy="outgoingScene", cascade={"persist", "remove"})
     */
    private $outgoingConnections = null;

    /**
     * @OneToMany(targetEntity="SceneConnection", mappedBy="incomingScene", cascade={"persist", "remove"})
     */
    private $incomingConnections = null;

    /**
     * @var array
     */
    private static $fillable = [
        "title",
        "description",
        "template"
    ];

    /* @var ?ArrayCollection */
    private $connectedScenes = null;

    /**
     * Constructor for a scene.
     */
    public function __construct()
    {
        $this->id = Uuid::uuid4()->toString();

        $this->connectionGroups = new ArrayCollection();
        $this->outgoingConnections = new ArrayCollection();
        $this->incomingConnections = new ArrayCollection();
    }

    /**
     * Returns the primary ID for this scene.
     * @return int
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Filters all connection groups for a specific name.
     * @param string $name
     * @return Collection
     */
    private function filterConnectionGroupCollectionByName(string $name): Collection
    {
        return $this->connectionGroups->filter(function(SceneConnectionGroup $group) use ($name) {
            if ($group->getName() === $name) {
                return true;
            } else {
                return false;
            }
        });
    }

    /**
     * Returns true if this scene has a connection group with a given name associated.
     * @param string $name
     * @return bool
     */
    public function hasConnectionGroup(string $name): bool
    {
        return count($this->filterConnectionGroupCollectionByName($name)) === 1 ? true : false;
    }

    /**
     * Returns a connection group entity associated with this scene by a given name.
     * @param string $name
     * @return \LotGD\Core\Models\SceneConnectionGroup
     */
    public function getConnectionGroup(string $name): SceneConnectionGroup
    {
        return $this->filterConnectionGroupCollectionByName($name)->first();
    }

    /**
     * Returns all connection groups associated with this scene.
     * @return Collection
     */
    public function getConnectionGroups(): Collection
    {
        return $this->connectionGroups;
    }

    /**
     * Adds a connection group to this scene.
     * @param SceneConnectionGroup $group
     * @throws ArgumentException
     */
    public function addConnectionGroup(SceneConnectionGroup $group): void
    {
        if ($this->connectionGroups->contains($group) === true) {
            throw new ArgumentException("This entity already owns the given connection group.");
        }

        if ($group->getScene()) {
            throw new ArgumentException("The given connection group is already owned by another scene entity.");
        }

        $group->setScene($this);
        $this->connectionGroups->add($group);
    }

    /**
     * Removes a connection group from this scene.
     * @param \LotGD\Core\Models\SceneConnectionGroup $group
     * @throws ArgumentException
     */
    public function dropConnectionGroup(SceneConnectionGroup $group): void
    {
        if ($this->connectionGroups->contains($group) === false) {
            throw new ArgumentException("This entity does not own the given connection group.");
        }

        $this->connectionGroups->removeElement($group);
    }

    /**
     * Lazy loading helper function - loads all scenes that are connected to this scene.
     */
    private function loadConnectedScenes(): void
    {
        if ($this->connectedScenes === null) {
            $connectedScenes = new ArrayCollection();

            foreach ($this->outgoingConnections as $connection) {
                $incomingScene = $connection->getIncomingScene();

                if ($connectedScenes->contains($incomingScene) === false) {
                    $connectedScenes->add($incomingScene);
                }
            }

            foreach ($this->incomingConnections as $connection) {
                $outgoingScenes = $connection->getOutgoingScene();

                if ($connectedScenes->contains($outgoingScenes) === false) {
                    $connectedScenes->add($outgoingScenes);
                }
            }

            $this->connectedScenes = $connectedScenes;
        }
    }

    /**
     * Returns a list of scenes that are connected to this scene.
     *
     * This procedure can get slow, especially if there are a lot of scenes connected
     * to one. Use this method only for the installation and removal of modules,
     * or for administrative purposes (like a scene graph).
     * @return ArrayCollection
     */
    public function getConnectedScenes(): ArrayCollection
    {
        $this->loadConnectedScenes();
        return $this->connectedScenes;
    }

    /**
     * Checks if the given scene is connected to this entity.
     * @param \LotGD\Core\Models\Scene $scene
     * @return bool True if yes.
     */
    public function isConnectedTo(Scene $scene): bool
    {
        $this->loadConnectedScenes();

        if ($this->connectedScenes->contains($scene)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Returns all collections of this entity.
     * @return Collection
     */
    public function getConnections(): Collection
    {
        return new ArrayCollection(
            array_merge(
                $this->outgoingConnections->toArray(),
                $this->incomingConnections->toArray()
            )
        );
    }

    /**
     * Adds a connection to the outgoing connections.
     * @param \LotGD\Core\Models\SceneConnection $connection
     */
    public function addOutgoingConnection(SceneConnection $connection): void
    {
        $this->outgoingConnections->add($connection);

        // If we already have loaded all connected scenes, we need to add the entry manually.
        if ($this->connectedScenes !== null) {
            $this->connectedScenes->add($connection->getIncomingScene());
        }
    }

    /**
     * Adds a connection to the incoming connections.
     * @param \LotGD\Core\Models\SceneConnection $connection
     */
    public function addIncomingConnection(SceneConnection $connection): void
    {
        $this->incomingConnections->add($connection);

        // If we already have loaded all connected scenes, we need to add the entry manually.
        if ($this->connectedScenes !== null) {
            $this->connectedScenes->add($connection->getOutgoingScene());
        }
    }

    /**
     * @inheritDoc
     */
    public function connect(
        SceneConnectable $connectable,
        int $directionality = self::Bidirectional
    ): SceneConnection {
        if ($connectable instanceof self) {
            if ($this === $connectable) {
                throw new ArgumentException("Cannot connect a scene to itself.");
            }

            if ($this->isConnectedTo($connectable)) {
                throw new ArgumentException(
                    "The given scene (ID {$connectable->getId()}) is already connected to this (ID {$this->getId()}) one."
                );
            }

            $connection = new SceneConnection($this, $connectable, $directionality);

            $outgoingScene = $this;
            $incomingScene = $connectable;
        } else {
            if ($this === $connectable->getScene()) {
                throw new ArgumentException("Cannot connect a scene to itself.");
            }

            if ($this->isConnectedTo($connectable->getScene())) {
                throw new ArgumentException(
                    "The given scene (ID {$connectable->getId()}) is already connected to this (ID {$this->getId()}) one."
                );
            }

            $connection = new SceneConnection($this, $connectable->getScene(), $directionality);
            $connection->setIncomingConnectionGroupName($connectable->getName());

            $outgoingScene = $this;
            $incomingScene = $connectable->getScene();
        }

        $outgoingScene->addOutgoingConnection($connection);
        $incomingScene->addIncomingConnection($connection);

        return $connection;
    }
}
