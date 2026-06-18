<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class BenutzernameAendernType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('username', TextType::class, [
            'label'       => 'Neuer Benutzername',
            'constraints' => [
                new Assert\NotBlank(message: 'Bitte einen Benutzernamen eingeben.'),
                new Assert\Length(min: 3, max: 30,
                    minMessage: 'Mindestens 3 Zeichen.',
                    maxMessage: 'Maximal 30 Zeichen.'),
                new Assert\Regex(pattern: '/^[a-zA-Z0-9_\-]+$/',
                    message: 'Nur Buchstaben, Zahlen, - und _ erlaubt.'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_protection' => true, 'csrf_field_name' => '_token']);
    }
}
