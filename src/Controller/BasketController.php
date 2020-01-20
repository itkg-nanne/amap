<?php

namespace App\Controller;

use App\Entity\Basket;
use App\Entity\User;
use App\EntityManager\CreditManager;
use App\EntityManager\ProductManager;
use App\Form\BillFilterType;
use App\Form\CommandType;
use App\Form\ModelType;
use App\Form\SynthesesType;
use App\EntityManager\BasketManager;
use App\Helper\MailHelper;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Bundle\SnappyBundle\Snappy\Response\PdfResponse;
use Knp\Snappy\Pdf;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BasketController extends AbstractController
{
    /**
     * @Route(
     *     "/{role}/basket/view",
     *     name="basket_view",
     *     requirements={"role"="admin|referent|producer|member"},
     * )
     */
    public function basketViewAction(Request $request, BasketManager $basketManager, ProductManager $productManager, EntityManagerInterface $entityManager, Pdf $knpSnappy, ParameterBagInterface $parameterBag)
    {
        $form = $this->createForm(BillFilterType::class);
        $form->handleRequest($request);
        $baskets = [];
        if ($form->isSubmitted() && $form->isValid() && null !== $form->get('start')->getData() && null !== $form->get('end')->getData()) {
            $baskets = $entityManager->getRepository(Basket::class)->findByUserAndDate($this->getUser(), $form->get('start')->getData(), $form->get('end')->getData());
        } else {
            $modelBaskets = $entityManager->getRepository(Basket::class)->findByFrozenAndModel(0);
            foreach ($modelBaskets as $modelBasket) {
                $baskets[] = $entityManager->getRepository(Basket::class)->findOneByParentAndUser($modelBasket, $this->getUser()) ?? $basketManager->createBasket($modelBasket);
            }
        }

        $products = $productManager->getProductsFromBaskets($baskets);
        $productManager->orderProducts($products);
        $isPdf = $form->has('pdf') && $form->get('pdf')->getData();
        $html = $this->renderView('basket/view.html.twig', [
            'title' => 'Mes commandes',
            'form' => $form->createView(),
            'baskets' => $baskets,
            'products' => $products,
            'isPdf' => $isPdf,
        ]);
        if ($isPdf) {
            $filename = 'mes_commandes';
            if (count($baskets) > 0) {
                $maxDate = max(array_map(function (Basket $basket) {
                    return $basket->getDate();
                }, $baskets));
                $minDate = min(array_map(function (Basket $basket) {
                    return $basket->getDate();
                }, $baskets));
                $filename .= '_'.$minDate->format('dmY').'_'.$maxDate->format('dmY');
            }

            return new PdfResponse(
                $knpSnappy->getOutputFromHtml($html, ['user-style-sheet' => $parameterBag->get('kernel.project_dir').'/public/assets/css/pdf-color-page-break.css']),
                $filename.'.pdf'
            );
        }

        return new Response($html);
    }

    /**
     * @Route(
     *     "/{role}/basket/form",
     *     name="basket_form",
     *     requirements={"role"="admin|referent|producer|member"},
     * )
     */
    public function basketEditAction(Request $request, BasketManager $basketManager, ProductManager $productManager, EntityManagerInterface $entityManager, string $role)
    {
        $modelBaskets = $entityManager->getRepository(Basket::class)->findByFrozenAndModel(0);
        $baskets = [];
        $isNew = true;
        foreach ($modelBaskets as $modelBasket) {
            $basket = $entityManager->getRepository(Basket::class)->findOneByParentAndUser($modelBasket, $this->getUser());
            $isNew = $isNew && (null == $basket);
            $baskets[] = $basket ?? $basketManager->createBasket($modelBasket);
        }
        $products = $productManager->getProductsFromBaskets($baskets, -1);
        $productManager->orderProducts($products);

        $form = $this->createForm(CommandType::class, $baskets);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $key = 0;
            while ($form->has('basket_'.$key)) {
                $entityManager->persist($form->get('basket_'.$key)->getData());
                ++$key;
            }
            $entityManager->flush();
            $this->addFlash('success', 'Le panier a été mis à jour.');

            return $this->forward('App\Controller\BasketController::basketViewAction', ['role' => $role]);
        }

        return $this->render('basket/form.html.twig', [
            'title' => 'Mise à jour panier',
            'form' => $form->createView(),
            'role' => $role,
            'products' => $products,
            'isNew' => $isNew,
        ]);
    }

    /**
     * @Route(
     *     "/admin/basket/models",
     *     name="basket_models",
     * )
     */
    public function modelListingAction(Request $request, EntityManagerInterface $entityManager, MailHelper $mailHelper)
    {
        $baskets = $entityManager->getRepository(Basket::class)->findModel();
        $openModels = $entityManager->getRepository(Basket::class)->findByFrozenAndModel(0);
        $list = $entityManager->getRepository(User::class)->countBasketByUser($openModels);
        $getOpenModels = count($openModels) > 0;
        $options = [
            'baskets' => $baskets,
            'title' => 'Modèles de panier',
        ];
        $mailsParameters = [];
        $options['formMailUp'] = false;
        if ($getOpenModels && count($list) > 0) {
            $options['formMailUp'] = true;
            $mailsParameters[0] = [
                'list' => $list,
                'subject' => 'Relance commande AMAP hommes de terre',
                'template' => 'emails/upcommande',
            ];
        }
        $options['formMailInfo'] = false;
        if ($getOpenModels) {
            $options['formMailInfo'] = true;
            $mailsParameters[1] = [
                'list' => $entityManager->getRepository(User::class)->findByRoleAndActive('ROLE_MEMBER'),
                'subject' => 'Commande AMAP Hommes de terre',
                'template' => 'emails/infocommande',
                'mailOptions' => ['baskets' => $openModels],
            ];
        }
        if (!empty($mailsParameters)) {
            $options = array_merge($options, $mailHelper->createMailForm($request, $mailsParameters));
        }

        return $this->render('basket/models.html.twig', $options);
    }

    /**
     * @Route(
     *     "/admin/basket/model/{id}",
     *     name="basket_model",
     *     requirements={"id"="\d+"},
     *     defaults={"id"=0}
     * )
     */
    public function modelEditAction(Request $request, BasketManager $basketManager, ProductManager $productManager, EntityManagerInterface $entityManager, Basket $basket = null)
    {
        $producers = $entityManager->getRepository(User::class)->findByDeleveries();
        $isNew = $basket ? false : true;
        $basket = $basket ?? $basketManager->createModel();
        $models = array_diff($entityManager->getRepository(Basket::class)->findByFrozenAndModel(0), [$basket]);

        $products = $productManager->getProductsFromBaskets($models, -1);
        $products = $productManager->getProductsFromBaskets([$basket], -1, $products);
        $productManager->orderProducts($products);

        $form = $this->createForm(ModelType::class, $basket, [
            'disabled' => $basket->isFrozen(),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $previousProducts = $basketManager->getSelectedProductsList($basket);
            foreach ($form->get('productQuantityCollection') as $key => $child) {
                $productQuantity = $child->getData();
                $productQuantity->setQuantity($child->get('active')->getData() ? 1 : 0);
                $entityManager->persist($productQuantity);
            }
            $basketManager->changeModel($basket, $previousProducts);
            $entityManager->persist($basket);
            $entityManager->flush();
            $this->addFlash('success', $isNew ? 'Le modèle a été créé.' : 'Le modèle a été mis à jour.');

            return $this->forward('App\Controller\BasketController::modelListingAction');
        }

        return $this->render('basket/model.html.twig', [
            'title' => 'Mise à jour modèle de panier',
            'form' => $form->createView(),
            'models' => $models,
            'products' => $products,
            'producers' => $producers,
        ]);
    }

    /**
     * @Route(
     *     "/admin/syntheses/basket",
     *     name="basket_syntheses",
     * )
     */
    public function synthesesAction(Request $request, MailHelper $mailHelper, BasketManager $basketManager, CreditManager $creditManager, EntityManagerInterface $entityManager, Pdf $knpSnappy, ParameterBagInterface $parameterBag)
    {
        $form = $this->createForm(SynthesesType::class);
        $form->handleRequest($request);
        $type = $form->has('type') ? $form->get('type')->getData() : '';
        $isPdf = false;
        $options = [
            'title' => 'Extraction',
            'form' => $form->createView(),
            'type' => $type,
        ];
        $options['formMailExtraction'] = false;
        if ($form->isSubmitted() && $form->isValid()) {
            $isPdf = $form->has('pdf') && $form->get('pdf')->isClicked();
            $options['isPdf'] = $isPdf;
            if ($form->has('submitCredit') && $form->get('submitCredit')->isClicked()) {
                $creditManager->generateCredit(
                    $form->has('date') && null != $form->get('date')->getData() ? $form->get('date')->getData() : $form->get('start')->getData(),
                    $form->has('date') && null != $form->get('date')->getData() ? clone $form->get('date')->getData() : $form->get('end')->getData(),
                    $form->has('product') ? $form->get('product')->getData() : null,
                    $form->has('member') ? $form->get('member')->getData() : null,
                    $form->has('quantity') ? $form->get('quantity')->getData() : null
                );
                $entityManager->flush();

                return $this->forward('App\Controller\CreditController::creditListingAction', ['role' => 'admin']);
            }

            list($tables, $parameters) = $basketManager->generateSyntheses($form);
            $options['tables'] = $tables;
            $options['parameters'] = $parameters;
            $mailsParameters = [];
            if (SynthesesType::INVOICE_BY_MEMBER == $type || SynthesesType::INVOICE_BY_PRODUCER == $type || SynthesesType::INVOICE_BY_PRODUCER_BY_MEMBER == $type) {
                $options['formMailExtraction'] = true;
                foreach ($tables as $tableName => $table) {
                    $mailsParameters['formMail'][$tableName] = [
                        'list' => [$parameters[$tableName]],
                        'subject' => 'AMAP Hommes de terre '.SynthesesType::LABELS[$type],
                        'template' => 'basket/synthesis',
                        'mailOptions' => [
                            'tableName' => $tableName,
                            'table' => $table,
                            'parameters' => $parameters[$tableName],
                            'form' => $form->createView(),
                            'type' => $type,
                        ],
                    ];
                }
            }
            if (!empty($mailsParameters)) {
                $options = array_merge($options, $mailHelper->handleMailForm($mailsParameters, $form));
            }
        }
        $options['isPdf'] = $isPdf;
        $html = $this->renderView('basket/syntheses.html.twig', $options);
        if ($isPdf) {
            $css = $form->has('css') ? $form->get('css')->getData() : 'pdf-color-page-break';
            $filename = null !== $type ? SynthesesType::FILES[$type] : 'extraction';
            if ($form->has('start') && $form->get('start')->getData()) {
                $filename .= '_'.$form->get('start')->getData()->format('dmY');
            }
            if ($form->has('end') && $form->get('end')->getData()) {
                $filename .= '_'.$form->get('end')->getData()->format('dmY');
            }

            return new PdfResponse(
                $knpSnappy->getOutputFromHtml($html, ['user-style-sheet' => $parameterBag->get('kernel.project_dir').'/public/assets/css/'.$css.'.css']),
                $filename.'.pdf'
            );
        }

        return new Response($html);
    }

    /**
     * @Route(
     *     "/admin/basket/frozen/{id}",
     *     name="basket_frozen",
     * )
     */
    public function frozenAction(EntityManagerInterface $entityManager, BasketManager $basketManager, Basket $basket)
    {
        $frozen = !$basket->isFrozen();
        $basket->setFrozen($frozen);
        $basketManager->changeBasket($basket);
        $entityManager->flush();
        $this->addFlash('success', $frozen ? 'Le modèle de panier a été fermé.' : 'Le modèle de panier a été réouvert.');

        return $this->forward('App\Controller\BasketController::modelListingAction');
    }

    /**
     * @Route(
     *     "/admin/basket/delete/{id}",
     *     name="basket_delete",
     * )
     */
    public function deleteAction(EntityManagerInterface $entityManager, BasketManager $basketManager, Basket $basket)
    {
        $basketManager->setDeleted($basket);
        $entityManager->flush();
        $this->addFlash('success', 'L\'entrée du modèle de panier a été supprimée.');

        return $this->forward('App\Controller\BasketController::modelListingAction');
    }
}
