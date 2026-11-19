<?php

namespace App\Form;

use App\Entity\Media;
use App\Enum\MediaType as MediaTypeEnum;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;

/**
 * Formulaire d'un média produit : image (upload) ou vidéo (upload OU URL YouTube/Vimeo).
 * Les contraintes sur le champ `upload` sont adaptées dynamiquement au type choisi.
 */
class MediaType extends AbstractType
{
    private const IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    private const VIDEO_MIME_TYPES = [
        'video/mp4',
        'video/webm',
        'video/ogg',
        'video/quicktime',
    ];

    private const IMAGE_MAX_SIZE = '5M';
    private const VIDEO_MAX_SIZE = '50M';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'Type',
                'choices' => [
                    MediaTypeEnum::Image->label() => MediaTypeEnum::Image->value,
                    MediaTypeEnum::Video->label() => MediaTypeEnum::Video->value,
                ],
                'expanded' => true,
                'multiple' => false,
                'required' => true,
                'placeholder' => false,
            ])
            ->add('upload', FileType::class, $this->uploadFieldOptions(MediaTypeEnum::Image->value))
            ->add('videoUrl', UrlType::class, [
                'label' => 'URL vidéo (YouTube / Vimeo)',
                'required' => false,
                'help' => 'Optionnel — pour intégrer une vidéo hébergée. Ex : https://youtu.be/xxxxxx',
                'constraints' => [
                    new Url(requireTld: true),
                    new Length(max: 500),
                ],
            ])
            ->add('alt', TextType::class, [
                'label' => 'Texte alternatif',
                'required' => false,
            ])
            ->add('position', IntegerType::class, ['required' => false])
            ->add('isCover', CheckboxType::class, [
                'required' => false,
                'label' => 'Image principale',
                'help' => 'Ignoré pour les vidéos.',
            ])
            ->add('isWholesale', CheckboxType::class, [
                'required' => false,
                'label' => 'Image grossiste',
                'help' => 'Affichée uniquement sur la fiche vente en gros.',
            ]);

        // Preview au chargement du formulaire
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $media = $event->getData();
            if (!$media instanceof Media) {
                return;
            }

            $this->rebuildUploadField($event->getForm(), $media->getType(), $media);
        });

        // Reconfigurer les contraintes du champ upload en fonction du type soumis
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();
            $submittedType = \is_array($data) && isset($data['type']) && \is_string($data['type'])
                ? $data['type']
                : MediaTypeEnum::Image->value;

            if (!\in_array($submittedType, [MediaTypeEnum::Image->value, MediaTypeEnum::Video->value], true)) {
                $submittedType = MediaTypeEnum::Image->value;
            }

            $this->rebuildUploadField($event->getForm(), $submittedType, null);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Media::class]);
    }

    private function rebuildUploadField(FormInterface $form, string $type, ?Media $media): void
    {
        $options = $this->uploadFieldOptions($type);

        if ($media !== null) {
            $preview = $this->buildPreviewHtml($media);
            if ($preview !== null) {
                $options['help']      = $preview;
                $options['help_html'] = true;
            }
        }

        $form->add('upload', FileType::class, $options);
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadFieldOptions(string $type): array
    {
        if ($type === MediaTypeEnum::Video->value) {
            return [
                'label'    => 'Fichier vidéo',
                'required' => false,
                'mapped'   => true,
                'help'     => 'MP4 / WebM — 50 Mo max. Laissez vide si vous utilisez une URL YouTube/Vimeo.',
                'constraints' => [
                    new File(
                        maxSize: self::VIDEO_MAX_SIZE,
                        mimeTypes: self::VIDEO_MIME_TYPES,
                        mimeTypesMessage: 'Veuillez uploader une vidéo valide (MP4, WebM, OGG, MOV).',
                    ),
                ],
            ];
        }

        return [
            'label'    => 'Image',
            'required' => false,
            'mapped'   => true,
            'constraints' => [
                new File(
                    maxSize: self::IMAGE_MAX_SIZE,
                    mimeTypes: self::IMAGE_MIME_TYPES,
                    mimeTypesMessage: 'Veuillez uploader une image valide (JPEG, PNG, WEBP, GIF).',
                ),
            ],
        ];
    }

    private function buildPreviewHtml(Media $media): ?string
    {
        $filename = $media->getFilename();
        if (!\is_string($filename) || $filename === '') {
            return null;
        }

        if ($media->isVideoUpload()) {
            $url = '/assets/videos/products/' . $filename;

            return \sprintf(
                '<div style="margin-top:6px;"><video src="%s" style="max-height:140px;border-radius:6px;border:1px solid #e5e7eb;" controls preload="metadata"></video></div>',
                htmlspecialchars($url, ENT_QUOTES),
            );
        }

        $baseUrl = $this->resolveBaseUrl($media);
        $thumb   = $media->getThumbnailFilename();

        return \sprintf(
            '<div style="margin-top:6px;"><img src="%s/%s" alt="" style="max-height:120px;border-radius:6px;border:1px solid #e5e7eb;" onerror="this.onerror=null;this.src=\'%s/%s\';"></div>',
            htmlspecialchars($baseUrl, ENT_QUOTES),
            htmlspecialchars($thumb, ENT_QUOTES),
            htmlspecialchars($baseUrl, ENT_QUOTES),
            htmlspecialchars($filename, ENT_QUOTES),
        );
    }

    private function resolveBaseUrl(Media $media): string
    {
        if ($media->getProduct() !== null) {
            return '/assets/images/products';
        }

        if (!$media->getBlogs()->isEmpty()) {
            return '/assets/images/blogs';
        }

        return '/assets/images/products';
    }
}
