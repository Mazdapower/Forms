<?php

namespace AppBundle\Fixture;

use AppBundle\Entity\Form;
use Doctrine\Common\Persistence\ObjectManager;
use Ds\Component\Database\Fixture\ResourceFixture;
use Ds\Component\Formio\Exception\ValidationException;
use Ds\Component\Formio\Model\User as FormioUser;

/**
 * Class FormFixture
 */
abstract class FormFixture extends ResourceFixture
{
    /**
     * {@inheritdoc}
     */
    public function load(ObjectManager $manager)
    {
        $connection = $manager->getConnection();
        $platform = $connection->getDatabasePlatform()->getName();

        switch ($platform) {
            case 'postgresql':
                $connection->exec('ALTER SEQUENCE app_form_id_seq RESTART WITH 1');
                break;
        }

        $env = $this->container->get('kernel')->getEnvironment();

        // @todo create mock server instead of skipping fixture
        if ('test' === $env) {
            return;
        }

        $configService = $this->container->get('ds_config.service.config');
        $service = $this->container->get('ds_api.api')->get('formio.authentication');
        $user = new FormioUser;
        $user
            ->setEmail($configService->get('ds_api.user.username'))
            ->setPassword($configService->get('ds_api.user.password'));
        $token = $service->login($user);
        $service = $this->container->get('ds_api.api')->get('formio.form');
        $service->setHeader('x-jwt-token', $token);
        $forms = $service->getList();

        foreach ($forms as $form) {
            if (in_array($form->getName(), ['user', 'admin', 'userLogin', 'userRegister'])) {
                // Skip base formio forms.
                continue;
            }

            try {
                $service->delete($form->getPath());
            } catch (ValidationException $exception) {
                // @todo this is so first time fixtures dont cause an error, handle "Invalid alias" better
            }
        }

        $api = $this->container->get('ds_api.api')->get('formio.role');
        $api->setHeader('x-jwt-token', $token);
        $roles = $api->getList();
        $objects = $this->parse($this->getResource());

        foreach ($objects as $object) {
            $form = new Form;
            $form
                ->setUuid($object->uuid)
                ->setOwner($object->owner)
                ->setOwnerUuid($object->owner_uuid)
                ->setType($object->type)
                ->setTenant($object->tenant);

            switch ($object->type) {
                case Form::TYPE_FORMIO:
                    $config = $object->config;

                    if (property_exists($config, 'components')) {
                        if (is_string($config->components)) {
                            $config->components = json_decode(file_get_contents(dirname(str_replace('{env}', $env, $this->getResource())).'/'.$config->components));
                        }
                    }

                    if (property_exists($config, 'submissionAccess')) {
                        if (is_string($config->submissionAccess)) {
                            $config->submissionAccess = json_decode(file_get_contents(dirname(str_replace('{env}', $env, $this->getResource())).'/'.$config->submissionAccess));
                            $submissionAccess = [];

                            foreach ($config->submissionAccess as $access) {
                                foreach ($access->roles as $key => $value) {
                                    foreach ($roles as $role) {
                                        if ($role->getMachineName() === $value) {
                                            $access->roles[$key] = $role->getId();
                                            break;
                                        }
                                    }
                                }

                                $submissionAccess[] = $access;
                            }

                            $config->submissionAccess = $submissionAccess;
                        }
                    }

                    $form->setConfig($config);
                    break;
            }

            $manager->persist($form);
            $manager->flush();
        }
    }
}
