<?php

namespace Webkul\UVDesk\SupportCenterBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\EventDispatcher\GenericEvent;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\Thread;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\Website;
use Symfony\Component\Validator\Constraints\DateTime;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\TicketRating;
use Webkul\UVDesk\SupportCenterBundle\Form\Ticket as TicketForm;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Webkul\UVDesk\SupportCenterBundle\Entity\KnowledgebaseWebsite;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\Ticket as TicketEntity;
use Webkul\UVDesk\CoreFrameworkBundle\Workflow\Events as CoreWorkflowEvents;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Webkul\UVDesk\CoreFrameworkBundle\Services\UserService;
use Webkul\UVDesk\CoreFrameworkBundle\Services\UVDeskService;
use Webkul\UVDesk\CoreFrameworkBundle\Services\TicketService;
use Webkul\UVDesk\CoreFrameworkBundle\FileSystem\FileSystem;
use Symfony\Component\Translation\TranslatorInterface;
use Webkul\UVDesk\CoreFrameworkBundle\Services\ReCaptchaService;


class Ticket extends Controller
{
    private $userService;
    private $eventDispatcher;
    private $translator;
    private $uvdeskService;
    private $ticketService;
    private $fileSystem;
    private $recaptchaService;

    public function __construct(UserService $userService, UVDeskService $uvdeskService,EventDispatcherInterface $eventDispatcher, TranslatorInterface $translator, TicketService $ticketService, FileSystem $fileSystem, ReCaptchaService $recaptchaService)
    {
        $this->userService = $userService;
        $this->eventDispatcher = $eventDispatcher;
        $this->translator = $translator;
        $this->uvdeskService = $uvdeskService;
        $this->ticketService = $ticketService;
        $this->fileSystem = $fileSystem;
        $this->recaptchaService = $recaptchaService;
    }

    protected function isWebsiteActive()
    {
        $entityManager = $this->getDoctrine()->getManager();
        $website = $entityManager->getRepository(Website::class)->findOneByCode('knowledgebase');

        if (!empty($website)) {
            $knowledgebaseWebsite = $entityManager->getRepository(KnowledgebaseWebsite::class)->findOneBy(['website' => $website->getId(), 'status' => 1]);
            
            if (!empty($knowledgebaseWebsite) && true == $knowledgebaseWebsite->getIsActive()) {
                return true;
            }
        }

        $this->noResultFound();
    }

    /**
     * If customer is playing with url and no result is found then what will happen
     * @return
     */
    protected function noResultFound()
    {
        throw new NotFoundHttpException('Not found !');
    }

    public function ticketadd(Request $request)
    {
        $this->isWebsiteActive();
        
        $formErrors = $errors = array();
        $em = $this->getDoctrine()->getManager();
        $website = $em->getRepository(Website::class)->findOneByCode('knowledgebase');
        $websiteConfiguration = $this->uvdeskService->getActiveConfiguration($website->getId());

        if (!$websiteConfiguration || !$websiteConfiguration->getTicketCreateOption() || ($websiteConfiguration->getLoginRequiredToCreate() && !$this->getUser())) {
            return $this->redirect($this->generateUrl('helpdesk_knowledgebase'));
        }

        $post = $request->request->all();

        if($request->getMethod() == "POST") {
            if ($this->getParameter('is_google_captcha_enabled') 
            && $this->recaptchaService->getReCaptchaResponse($request->request->get('g-recaptcha-response'))
            ) {
                $this->addFlash('warning', $this->translator->trans("Warning ! Please select correct CAPTCHA !"));
            } else {
                if($_POST) {
                    $error = false;
                    $message = '';
                    $ticketType = $em->getRepository('UVDeskCoreFrameworkBundle:TicketType')->find($request->request->get('type'));
                    
                    if($request->files->get('customFields') && !$this->fileSystem->validateAttachmentsSize($request->files->get('customFields'))) {
                        $error = true;
                        $this->addFlash(
                                'warning',
                                $this->translator->trans("Warning ! Files size can not exceed %size% MB", [
                                    '%size%' => $this->getParameter('max_upload_size')
                                ])
                            );
                    }
    
                    $ticket = new TicketEntity();
                    $loggedUser = $this->get('security.token_storage')->getToken()->getUser();
                    
                    if(!empty($loggedUser) && $loggedUser != 'anon.') {
                        
                        $form = $this->createForm(TicketForm::class, $ticket, [
                            'container' => $this->container,
                            'entity_manager' => $em,
                        ]);
                        $email = $loggedUser->getEmail();
                        try {
                            $name = $loggedUser->getFirstName() . ' ' . $loggedUser->getLastName();
                        } catch(\Exception $e) {
                            $name = explode(' ', strstr($email, '@', true));
                        }
                    } else {
                        $form = $this->createForm(TicketForm::class, $ticket, [
                            'container' => $this->container,
                            'entity_manager' => $em,
                        ]);
                        $email = $request->request->get('from');
                        $name = explode(' ', $request->request->get('name'));
                    }
    
                    $website = $em->getRepository('UVDeskCoreFrameworkBundle:Website')->findOneByCode('knowledgebase');
                    if(!empty($email) && $this->ticketService->isEmailBlocked($email, $website)) {
                        $request->getSession()->getFlashBag()->set('warning', $this->translator->trans('Warning ! Cannot create ticket, given email is blocked by admin.'));
                        return $this->redirect($this->generateUrl('helpdesk_customer_create_ticket'));
                    }
    
                    if($request->request->all())
                        $form->submit($request->request->all());
    
                    if ($form->isValid() && !count($formErrors) && !$error) {
                        $data = array(
                            'from' => $email, //email$request->getSession()->getFlashBag()->set('success', $this->translator->trans('Success ! Ticket has been created successfully.'));
                            'subject' => $request->request->get('subject'),
                            // @TODO: We need to filter js (XSS) instead of html
                            'reply' => strip_tags($request->request->get('reply')),
                            'firstName' => $name[0],
                            'lastName' => isset($name[1]) ? $name[1] : '',
                            'role' => 4,
                            'active' => true
                        );
    
                        $em = $this->getDoctrine()->getManager();
                        $data['type'] = $em->getRepository('UVDeskCoreFrameworkBundle:TicketType')->find($request->request->get('type'));
    
                        if(!is_object($data['customer'] = $this->container->get('security.token_storage')->getToken()->getUser()) == "anon.") {
                            $supportRole = $em->getRepository('UVDeskCoreFrameworkBundle:SupportRole')->findOneByCode("ROLE_CUSTOMER");
    
                            $customerEmail = $params['email'] = $request->request->get('from');
                            $customer = $em->getRepository('UVDeskCoreFrameworkBundle:User')->findOneBy(array('email' => $customerEmail));
                            $params['flag'] = (!$customer) ? 1 : 0;$request->getSession()->getFlashBag()->set('success', $this->translator->trans('Success ! Ticket has been created successfully.'));
    
                            $data['firstName'] = current($nameDetails = explode(' ', $request->request->get('name')));
                            $data['fullname'] = $request->request->get('name');
                            $data['lastName'] = ($data['firstName'] != end($nameDetails)) ? end($nameDetails) : " ";
                            $data['from'] = $customerEmail;
                            $data['role'] = 4;
                            $data['customer'] = $this->userService->createUserInstance($customerEmail, $data['fullname'], $supportRole, $extras = ["active" => true]);
                        } else {
                            $userDetail = $em->getRepository('UVDeskCoreFrameworkBundle:User')->find($data['customer']->getId());
                            $data['email'] = $customerEmail = $data['customer']->getEmail();
                            $nameCollection = [$userDetail->getFirstName(), $userDetail->getLastName()];
                            $name = implode(' ', $nameCollection);
                            $data['fullname'] = $name;
                        }
                        $data['user'] = $data['customer'];
                        $data['subject'] = $request->request->get('subject');
                        $data['source'] = 'website';
                        $data['threadType'] = 'create';
                        $data['message'] = htmlentities($data['reply']);
                        $data['createdBy'] = 'customer';
                        $data['attachments'] = $request->files->get('attachments');
    
                        if(!empty($request->server->get("HTTP_CF_CONNECTING_IP") )) {
                            $data['ipAddress'] = $request->server->get("HTTP_CF_CONNECTING_IP");
                            if(!empty($request->server->get("HTTP_CF_IPCOUNTRY"))) {
                                $data['ipAddress'] .= '(' . $request->server->get("HTTP_CF_IPCOUNTRY") . ')';
                            }
                        }
    
                        $thread = $this->ticketService->createTicketBase($data);
                        
                        if ($thread) {
                            $request->getSession()->getFlashBag()->set('success', $this->translator->trans('Success ! Ticket has been created successfully.'));
                        } else {
                            $request->getSession()->getFlashBag()->set('warning', $this->translator->trans('Warning ! Can not create ticket, invalid details.'));
                        }
    
                        // Trigger ticket created event
                        $event = new GenericEvent(CoreWorkflowEvents\Ticket\Create::getId(), [
                            'entity' => $thread->getTicket(),
                        ]);
    
                        $this->eventDispatcher->dispatch('uvdesk.automation.workflow.execute', $event);
    
                        if(null != $this->getUser()) {
                            return $this->redirect($this->generateUrl('helpdesk_customer_ticket_collection'));
                        } else {
                            return $this->redirect($this->generateUrl('helpdesk_knowledgebase'));
                        }
                        
                    } else {
                        $errors = $this->getFormErrors($form);
                        $errors = array_merge($errors, $formErrors);
                    }
                } else {
                    $this->addFlash(
                        'warning',
                        $this->translator->trans("Warning ! Post size can not exceed 25MB")
                    );
                }
    
                if(isset($errors) && count($errors)) {
                    $this->addFlash('warning', key($errors) . ': ' . reset($errors));
                }
            }
        }

        $breadcrumbs = [
            [
                'label' => $this->translator->trans('Support Center'),
                'url' => $this->generateUrl('helpdesk_knowledgebase')
            ],
            [
                'label' => $this->translator->trans("Create Ticket Request"),
                'url' => '#'
            ],
        ];

        return $this->render('@UVDeskSupportCenter/Knowledgebase/ticket.html.twig',
            array(
                'formErrors' => $formErrors,
                'errors' => json_encode($errors),
                'customFieldsValues' => $request->request->get('customFields'),
                'breadcrumbs' => $breadcrumbs,
                'post' => $post
            )
        );
    }

    public function ticketList(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $ticketRepo = $em->getRepository('UVDeskCoreFrameworkBundle:Ticket');

        $currentUser = $this->get('security.token_storage')->getToken()->getUser();
        if(!$currentUser || $currentUser == "anon.") {
            //throw error
        }
        
        $tickets = $ticketRepo->getAllCustomerTickets($currentUser);
        
        return $this->render('@UVDeskSupportCenter/Knowledgebase/ticketList.html.twig', array(
            'ticketList' => $tickets,
        ));
    }

    public function saveReply(int $id, Request $request)
    {
        $this->isWebsiteActive();
        $data = $request->request->all();
        $ticket = $this->getDoctrine()->getRepository('UVDeskCoreFrameworkBundle:Ticket')->find($id);

        if($_POST) {
            if(str_replace(' ','',str_replace('&nbsp;','',trim(strip_tags($data['message'], '<img>')))) != "") {
                if(!$ticket)
                    $this->noResultFound();
                $data['ticket'] = $ticket;
                $data['user'] = $this->userService->getCurrentUser();

                // @TODO: Refactor -> Why are we filtering only these two characters?
                $data['message'] = str_replace(['&lt;script&gt;', '&lt;/script&gt;'], '', $data['message']);

                $userDetail = $this->userService->getCustomerPartialDetailById($data['user']->getId());
                $data['fullname'] = $userDetail['name'];
                $data['source'] = 'website';
                $data['createdBy'] = 'customer';
                $data['attachments'] = $request->files->get('attachments');
                $thread = $this->ticketService->createThread($ticket, $data);

                $em = $this->getDoctrine()->getManager();
                $status = $em->getRepository('UVDeskCoreFrameworkBundle:TicketStatus')->findOneByCode($data['status']);
                if($status) {
                    $flag = 0;
                    if($ticket->getStatus() != $status) {
                        $flag = 1;
                    }

                    $ticket->setStatus($status);
                    $em->persist($ticket);
                    $em->flush();
                }

                // Trigger customer reply event
                $event = new GenericEvent(CoreWorkflowEvents\Ticket\CustomerReply::getId(), [
                    'entity' =>  $ticket,
                ]);

                $this->eventDispatcher->dispatch('uvdesk.automation.workflow.execute', $event);

                $this->addFlash('success', $this->translator->trans('Success ! Reply added successfully.'));
            } else {
                $this->addFlash('warning', $this->translator->trans('Warning ! Reply field can not be blank.'));
            }
        } else {
            $this->addFlash('warning', $this->translator->trans('Warning ! Post size can not exceed 25MB'));
        }

        return $this->redirect($this->generateUrl('helpdesk_customer_ticket',array(
            'id' => $ticket->getId()
        )));
    }

    public function tickets(Request $request)
    {
        $this->isWebsiteActive();

        return $this->render('@UVDeskSupportCenter/Knowledgebase/ticketList.html.twig',
            array(
                'searchDisable' => true
            )
        );
    }

    /**
     * ticketListXhrAction "Filter and sort ticket collection on ajax request"
     * @param Object $request "HTTP Request object"
     * @return JSON "JSON response"
     */
    public function ticketListXhr(Request $request)
    {
        $this->isWebsiteActive();

        $json = array();
        if($request->isXmlHttpRequest()) {
            $repository = $this->getDoctrine()->getRepository('UVDeskCoreFrameworkBundle:Ticket');
    
            $json = $repository->getAllCustomerTickets($request->query, $this->container);
        }

        $response = new Response(json_encode($json));
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * threadListXhrAction "Filter and sort user collection on ajx request"
     * @param Object $request "HTTP Request object"
     * @return JSON "JSON response"
     */
    public function threadListXhr(Request $request)
    {
        $this->isWebsiteActive();

        $json = array();
        if($request->isXmlHttpRequest()) {
            $ticket = $this->getDoctrine()->getRepository('UVDeskCoreFrameworkBundle:Ticket')->find($request->attributes->get('id'));
            // $this->denyAccessUnlessGranted('FRONT_VIEW', $ticket);

            $repository = $this->getDoctrine()->getRepository('UVDeskCoreFrameworkBundle:Thread');
            $json = $repository->getAllCustomerThreads($request->attributes->get('id'),$request->query, $this->container);
        }

        $response = new Response(json_encode($json));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

    public function ticketView($id, Request $request)
    {
        $this->isWebsiteActive();

        $entityManager = $this->getDoctrine()->getManager();
        $user = $this->userService->getSessionUser();
        $ticket = $entityManager->getRepository(TicketEntity::class)->findOneBy(['id' => $id]);
        
        if (empty($ticket) || ( (!empty($user)) && $user->getId() != $ticket->getCustomer()->getId()) ) {
            if(!$this->isCollaborator($ticket, $user)) {
                throw new NotFoundHttpException('Page Not Found!');
            }
        }

        if (!empty($user) && $user->getId() == $ticket->getCustomer()->getId()) {
            $ticket->setIsCustomerViewed(1);

            $entityManager->persist($ticket);
            $entityManager->flush();
        }
        
        $twigResponse = [
            'ticket' => $ticket,
            'searchDisable' => true,
            'initialThread' => $this->ticketService->getTicketInitialThreadDetails($ticket),
            'localizedCreateAtTime' => $this->userService->getLocalizedFormattedTime($user, $ticket->getCreatedAt()),
        ];

        return $this->render('@UVDeskSupportCenter/Knowledgebase/ticketView.html.twig', $twigResponse);
    }

    // Check if user is collaborator for the ticket
    public function isCollaborator($ticket, $user) {
        $isCollaborator = false;
        if(!empty($ticket->getCollaborators()->toArray())) {
            foreach($ticket->getCollaborators()->toArray() as $collaborator) {
                if($collaborator->getId() == $user->getId()) {
                    $isCollaborator = true;
                }
            }
        }
        return $isCollaborator;
    }

    // Ticket rating
    public function rateTicket(Request $request) {

        $this->isWebsiteActive();
        $json = array();
        $em = $this->getDoctrine()->getManager();
        $data = json_decode($request->getContent(), true);
        $id = $data['id'];
        $count = intval($data['rating']);
        
        if($count > 0 || $count < 6) {
            $ticket = $em->getRepository('UVDeskCoreFrameworkBundle:Ticket')->find($id);
            $customer = $this->userService->getCurrentUser();
            $rating = $em->getRepository('UVDeskCoreFrameworkBundle:TicketRating')->findOneBy(array('ticket' => $id,'customer'=>$customer->getId()));
            if($rating) {
                $rating->setcreatedAt(new \DateTime);
                $rating->setStars($count);
                $em->persist($rating);
                $em->flush();
            } else {
                $rating = new TicketRating();
                $rating->setStars($count);
                $rating->setCustomer($customer);
                $rating->setTicket($ticket);
                $em->persist($rating);
                $em->flush();
            }
            $json['alertClass'] = 'success';
            $json['alertMessage'] = $this->translator->trans('Success ! Rating has been successfully added.');
        } else {
            $json['alertClass'] = 'danger';
            $json['alertMessage'] = $this->translator->trans('Warning ! Invalid rating.');
        }

        $response = new Response(json_encode($json));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

    public function downloadAttachmentZip(Request $request)
    {
        $threadId = $request->attributes->get('threadId');
        $attachmentRepository = $this->getDoctrine()->getManager()->getRepository('UVDeskCoreFrameworkBundle:Attachment');

        $attachment = $attachmentRepository->findByThread($threadId);

        if (!$attachment) {
            $this->noResultFound();
        }

        $zipname = 'attachments/' .$threadId.'.zip';
        $zip = new \ZipArchive;

        $zip->open($zipname, \ZipArchive::CREATE);
        if(count($attachment)){
            foreach ($attachment as $attach) {
                $zip->addFile(substr($attach->getPath(), 1)); 
            }
        }
        $zip->close();

        $response = new Response();
        $response->setStatusCode(200);
        $response->headers->set('Content-type', 'application/zip');
        $response->headers->set('Content-Disposition', 'attachment; filename=' . $threadId . '.zip');
        $response->sendHeaders();
        $response->setContent(readfile($zipname));

        return $response;
    }
    public function downloadAttachment(Request $request)
    {
        $attachmendId = $request->attributes->get('attachmendId');
        $attachmentRepository = $this->getDoctrine()->getManager()->getRepository('UVDeskCoreFrameworkBundle:Attachment');
        $attachment = $attachmentRepository->findOneById($attachmendId);
        $baseurl = $request->getScheme() . '://' . $request->getHttpHost() . $request->getBasePath();

        if (!$attachment) {
            $this->noResultFound();
        }

        $path = $this->get('kernel')->getProjectDir() . "/public/". $attachment->getPath();

        $response = new Response();
        $response->setStatusCode(200);
        
        $response->headers->set('Content-type', $attachment->getContentType());
        $response->headers->set('Content-Disposition', 'attachment; filename='. $attachment->getName());
        $response->sendHeaders();
        $response->setContent(readfile($path));
        
        return $response;
    }
    
    public function ticketCollaboratorXhr(Request $request)
    {
        $json = array();
        $content = json_decode($request->getContent(), true);
        $em = $this->getDoctrine()->getManager();
        $ticket = $em->getRepository('UVDeskCoreFrameworkBundle:Ticket')->find($content['ticketId']);
        
        if ($request->getMethod() == "POST") {
            if ($content['email'] == $ticket->getCustomer()->getEmail()) {
                $json['alertClass'] = 'danger';
                $json['alertMessage'] = $this->translator->trans('Error ! Can not add customer as a collaborator.');
            } else {
                $data = array(
                    'from' => $content['email'],
                    'firstName' => ($firstName = ucfirst(current(explode('@', $content['email'])))),
                    'lastName' => ' ',
                    'role' => 4,
                );

                $supportRole = $em->getRepository('UVDeskCoreFrameworkBundle:SupportRole')->findOneByCode('ROLE_CUSTOMER');
                $collaborator = $this->userService->createUserInstance($data['from'], $data['firstName'], $supportRole, $extras = ["active" => true]);
                
                $checkTicket = $em->getRepository('UVDeskCoreFrameworkBundle:Ticket')->isTicketCollaborator($ticket,$content['email']);
                
                if (!$checkTicket) {
                    $ticket->addCollaborator($collaborator);
                    $em->persist($ticket);
                    $em->flush();
                    $ticket->lastCollaborator = $collaborator;
                    $collaborator = $em->getRepository('UVDeskCoreFrameworkBundle:User')->find($collaborator->getId());
                   

                    $json['collaborator'] =  $this->userService->getCustomerPartialDetailById($collaborator->getId());
                    $json['alertClass'] = 'success';
                    $json['alertMessage'] = $this->translator->trans('Success ! Collaborator added successfully.');
                } else {
                    $json['alertClass'] = 'danger';
                    $json['alertMessage'] = $this->translator->trans('Error ! Collaborator is already added.');
                }
            }
        } elseif ($request->getMethod() == "DELETE") {
            $collaborator = $em->getRepository('UVDeskCoreFrameworkBundle:User')->findOneBy(array('id' => $request->attributes->get('id')));
            
            if ($collaborator) {
                $ticket->removeCollaborator($collaborator);
                $em->persist($ticket);
                $em->flush();

                $json['alertClass'] = 'success';
                $json['alertMessage'] = $this->translator->trans('Success ! Collaborator removed successfully.');
            } else {
                $json['alertClass'] = 'danger';
                $json['alertMessage'] = $this->translator->trans('Error ! Invalid Collaborator.');
            }
        }

        $response = new Response(json_encode($json));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
