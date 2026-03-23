<?php
namespace IiifManifestDerivatives;

use Omeka\Module\AbstractModule;
use Laminas\EventManager\SharedEventManagerInterface;

class Module extends AbstractModule
{
    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            '*',
            'iiifserver.manifest',
            [$this, 'updateManifest'],
            -100
        );
    }

    public function updateManifest($event)
    {
        $params   = $event->getParams();
        $manifest = $params['manifest'] ?? null;
        $resource = $params['resource'] ?? null;

        if (!$manifest || !$resource) {
            return;
        }

        if ($resource->resourceName() !== 'items') {
            return;
        }

        $identifier = $this->getDcIdentifier($resource);
        if (!$identifier) {
            return;
        }

        // Leggi configurazione direttamente dalle impostazioni di IiifServer
        $settings    = $resource->getServiceLocator()->get('Omeka\Settings');
        $mediaApiUrl = rtrim((string) $settings->get('iiifserver_media_api_url', ''), '/');
        $apiVersion  = (string) $settings->get('iiifserver_media_api_default_version', '3');

        if (!$mediaApiUrl) {
            return;
        }

        // Costruisci il base URL con la versione, come fa IiifServer internamente
        $encodedId = rawurlencode($identifier);
        $iiifBase  = $mediaApiUrl . '/' . $apiVersion . '/' . $encodedId;
        $iiifUrl   = $iiifBase . '/full/max/0/default.jpg';
        $thumbUrl  = $iiifBase . '/square/200,200/0/default.jpg';

        $width  = 0;
        $height = 0;
        foreach ($resource->media() as $media) {
            $data = $media->mediaData();
            if (!empty($data['width']) && !empty($data['height'])) {
                $width  = (int) $data['width'];
                $height = (int) $data['height'];
                break;
            }
            $ingestionData = $media->ingestionData();
            if (!empty($ingestionData['width']) && !empty($ingestionData['height'])) {
                $width  = (int) $ingestionData['width'];
                $height = (int) $ingestionData['height'];
                break;
            }
        }

        $serviceType = 'ImageService' . $apiVersion;

        $service = [[
            'id'      => $iiifBase,
            'type'    => $serviceType,
            'profile' => 'level2',
        ]];

        $thumbnail = [[
            'id'      => $thumbUrl,
            'type'    => 'Image',
            'format'  => 'image/jpeg',
            'width'   => 200,
            'height'  => 200,
            'service' => $service,
        ]];

        $manifest['thumbnail'] = $thumbnail;

        foreach ($manifest['items'] ?? [] as $canvas) {
            $canvas['thumbnail'] = $thumbnail;
            $canvas['width']     = $width;
            $canvas['height']    = $height;

            foreach ($canvas['items'] ?? [] as $annotationPage) {
                foreach ($annotationPage['items'] ?? [] as $annotation) {
                    $annotation['body'] = [
                        'id'      => $iiifUrl,
                        'type'    => 'Image',
                        'format'  => 'image/jpeg',
                        'width'   => $width,
                        'height'  => $height,
                        'service' => $service,
                    ];
                }
            }
        }
    }

    protected function getDcIdentifier($resource): ?string
    {
        $value = $resource->value('dcterms:identifier');
        if (!$value) {
            $value = $resource->value('dc:identifier');
        }
        return $value ? (string) $value : null;
    }
}