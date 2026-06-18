<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class EmailAendernType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label'       => 'Neue E-Mail-Adresse',
                'constraints' => [
                    new Assert\NotBlank(message: 'Bitte eine E-Mail-Adresse eingeben.'),
                    new Assert\Email(message: 'Bitte eine gültige E-Mail-Adresse eingeben.'),
                ],
            ])
            ->add('passwort', PasswordType::class, [
                'label' => 'Aktuelles Passwort (zur Bestätigung)',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_protection' => true, 'csrf_field_name' => '_token']);
    }
}
