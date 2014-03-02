<?php
/**
 * src/Webb/PostBundle/Controller/NoteController.php
 */

namespace Webb\PostBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Webb\PostBundle\Entity\Note;
use Webb\PostBundle\Entity\Log;
use Webb\PostBundle\Form\Type\NoteType;
use \DateTime;

class NoteController extends Controller
{
    public function showAction($ship, $id)
    {

        $user = $this->getUser();

        if($user) {
            $userid = $user->getId();
        }
        else {
            $userid = 0;
        }

        $note = $this->getDoctrine()->getManager()->createQueryBuilder()
            ->select('n, l, p, a, q, r, s')
            ->from('WebbPostBundle:Note', 'n')
            ->where('n.id = :id')
            ->setParameter('id', $id)
            ->innerJoin('n.location', 'l')
            ->innerJoin('n.persona', 'p')
            ->innerJoin('n.assignment', 'a')
            ->innerJoin('a.position', 'q')
            ->innerJoin('q.parent', 'r')
            ->innerJoin('p.rank', 's')
            ->getQuery()->getOneOrNullResult();


        if (!$note) {
            throw $this->createNotFoundException(
                'No note found for id '.$id
            );
        }

        $shortname = $ship;
        unset($ship);

        $ship = $this->getDoctrine()->getManager()->createQueryBuilder()
            ->select('s, f')
            ->from('WebbShipBundle:Ship', 's')
            ->where('s.shortname = :shortname')->setParameter('shortname', $shortname)
            ->innerJoin('s.fleet', 'f')
            ->getQuery()->getOneOrNullResult();

        if (!$ship) {
            throw $this->createNotFoundException(
                'Ship not found'
            );
        }
        /*elseif ($ship->getFleet()->getId() != $fleet) {
            return $this->redirect($this->generateUrl('webb_ship_ship_view', array('fleet' => $ship->getFleet()->getId(), 'shortname' => $shortname)));
        }*/

        if($userid) {
            $new = true;

            foreach($note->getHistory() as $item) {
                if($item->getUser()->getId()) {
                    $new = false;
                    break;
                }
            }

            if($new) {
                $history = new \Webb\PostBundle\Entity\History();
                $history->setUser($user)
                    ->setNote($note);
                $em = $this->getDoctrine()->getManager();
                $em->persist($history);
                $em->flush();
            }
        }

        $previous = $this->getDoctrine()->getManager()->createQueryBuilder()
            ->select('n')
            ->from('WebbPostBundle:Note', 'n')
            ->where('n.ship = :ship_id AND n.id < :note_id')->setParameter('ship_id', $ship->getId())
            ->setParameter('note_id', $id)
            ->orderBy('n.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        $nextcron = $this->getDoctrine()->getManager()->createQueryBuilder()
            ->select('n')
            ->from('WebbPostBundle:Note', 'n')
            ->where('n.ship = :ship_id AND n.id > :note_id')->setParameter('ship_id', $ship->getId())
            ->setParameter('note_id', $id)
            ->orderBy('n.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        $nextloc = $this->getDoctrine()->getManager()->createQueryBuilder()
            ->select('n')
            ->from('WebbPostBundle:Note', 'n')
            ->where('n.ship = :ship_id AND n.id > :note_id AND n.location = :note_location')
            ->setParameter('ship_id', $ship->getId())
            ->setParameter('note_id', $id)
            ->setParameter('note_location', $note->getLocation())
            ->orderBy('n.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        return $this->render('WebbPostBundle:Note:show.html.twig', array('note' => $note, 'ship' => $ship, 'previous' => $previous, 'nextcron' => $nextcron, 'nextloc' => $nextloc));
    }

    public function createAction($fleet, $ship, $parent_id,  Request $request)
    {
        $note = new Note();

        $ship = $this->getDoctrine()->getRepository('WebbShipBundle:Ship')->findOneBy(array('shortname' => $ship));

        $note->setUser($this->getUser());
        $note->setShip($ship);

        if(!is_null($parent_id)) {
	        $parent = $this->getDoctrine()->getRepository('WebbPostBundle:Note')->find($parent_id);

            $note->setContent("> ".str_replace("\n", "\n> ", trim($parent->getContent()))."\n\n");
            $note->setActivity($parent->getActivity());
            $note->setParent($parent);

    	    $method = "webb_post_note_reply";
        }
        else {
            $method = "webb_post_note_create";
            $parent = false;
        }

	    $form = $this->createForm(new NoteType(), $note, array('ship' => $ship->getId(), 'user' => $this->getUser()->getId()));
        
	    if ($request->getMethod() == 'POST') {
            $form->bind($request);

	        $note->setPersona($note->getAssignment()->getPersona());

            $time = new DateTime;
            $time->setTimestamp(time());

            $note->setDate($time);	    

            if ($form->isValid()) {
                // perform some action, such as saving the task to the database
                $em = $this->getDoctrine()->getManager();
                $em->persist($note);
                $em->flush();

                return $this->redirect($this->generateUrl('webb_post_note_view', array('fleet' => $fleet, 'ship' => $ship->getShortName(), 'id' => $note->getID())));
            }
        }

        return $this->render('WebbPostBundle:Note:create.html.twig', array(
            'fleet' => $fleet,
            'ship' => $note->getShip(),
            'form' => $form->createView(),
            'selected' => $parent,
            'method' => $method,
            'parent' => $parent,
            'id' => false,
        ));

    }

    public function editAction($fleet, $ship, $id, Request $request)
    {
        $note = $this->getDoctrine()->getRepository('WebbPostBundle:Note')->find($id);
        $form = $this->createForm(new NoteType(), $note);

        if (!$note) {
            throw $this->createNotFoundException(
                'No post found for id '.$id
            );
        }

        if ($request->getMethod() == 'POST') {
            $form->bind($request);

            if ($form->isValid()) {
                // perform some action, such as saving the task to the database
                $em = $this->getDoctrine()->getManager();
                $em->persist($note);
                $em->flush();

                return $this->redirect($this->generateUrl('webb_post_note_view', array('fleet' => $fleet, 'ship' => $ship, 'id' => $note->getID())));
            }
        }

        return $this->render('WebbPostBundle:Note:create.html.twig', array(
            'form' => $form->createView(),
            'fleet' => $fleet,
            'ship' => $note->getShip(),
	    'selected' => $note,
            'method' => "webb_post_note_edit",
            'parent' => $note->getParent(),
            'id' => $note->getId(),
        ));
    }

    public function recentPostsAction($ship, $note) {

        $user = $this->getUser();

        if($user) {
            $userid = $user->getId();
        }
        else {
            $userid = 0;
        }

        $notes = $this->getDoctrine()->getManager()->createQueryBuilder()
            ->select('note, location, persona, assignment, position, parent, rank, log, child, ship, fleet, log2')
            ->from('WebbPostBundle:Note', 'note')
            ->where('note.ship = :ship_id')
            ->setParameter('ship_id', $ship->getId())
            ->innerJoin('note.location', 'location')
            ->innerJoin('note.persona', 'persona')
            ->innerJoin('note.assignment', 'assignment')
	        ->innerJoin('assignment.position', 'position')
	        ->innerJoin('position.parent', 'parent')
            ->innerJoin('persona.rank', 'rank')
            ->innerJoin('note.ship', 'ship')
            ->innerJoin('ship.fleet', 'fleet')
            ->leftJoin('note.log', 'log')
            ->leftJoin('note.child', 'child')
            ->leftJoin('child.log', 'log2') //No idea why adding this drops the SQL hits.
            ->getQuery()->execute();

        $temp = array();
        $arr = array();
        $ids = array();

        foreach($notes as $item) {
            $temp[$item->getId()] = $item;
        }

        foreach($temp as &$item) {
            $arr = array_merge($arr, $this->getChildPost($temp, $item));
            $ids[] = $item->getId();
        }

        /**********
         * @TODO: YOU MUST REMOVE ME!!!!!!
         */

        $userid = $userid ? $userid : 1;

        // End mass panic

        if($userid) {
            $history_bld = $this->getDoctrine()->getManager()->createQueryBuilder()
                ->select('h')
                ->from('WebbPostBundle:History', 'h');
            $history_arr = $history_bld->where($history_bld->expr()->in('h.note', ':my_array'))
                ->setParameter('my_array', $ids)
                ->andWhere('h.user = :user_id')
                ->setParameter('user_id', $userid)
                ->getQuery()->execute();
        }

        $history = array();

        foreach($history_arr as $item) {
            $history[$item->getNote()->getId()] = $item->getNote()->getId();
        }

        return $this->render('WebbPostBundle:Note:recentposts.html.twig', array('notes' => $arr, 'ship' => $ship, 'note' => $note, 'history' => $history));
    }

    private function getChildPost(&$notes, $note, $indent = 0) {

        $arr = array();
        $arr[] = array('note' => $note, 'id' => $note->getId(), 'indent' => $indent);

        foreach($note->getChild() as $child) {
            $arr = array_merge($arr, $this->getChildPost($notes, $notes[$child->getId()], $indent + 1));
        }

        unset($notes[$note->getId()]);

        return $arr;

    }
}
