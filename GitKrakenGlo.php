<?php
/**
 * Created by PhpStorm.
 * User: spark
 * Date: 2019-02-19
 * Time: 15:17
 */

namespace Pomodone\Providers;


use Carbon\Carbon;
use ohmy\Auth2;
use PHPExtra\Sorter\Sorter;
use PHPExtra\Sorter\Strategy\ComplexSortStrategy;
use Pomodone\User;
use Pomodone\Utils;
use Silex\Application;
use Symfony\Component\HttpFoundation\RedirectResponse;
use GuzzleHttp\Client as GuzzleHttpClient;

class GitKrakenGlo extends BaseProvider
{

    const NAME = 'gitkraken_glo';
    const TITLE = 'GitKraken Glo';
    const CONTAINER_TITLE = 'board';

    /**
     * @param Application $app
     * @return void
     */
    public function add(Application $app)
    {
        Auth2::legs(3)
            # configuration
            ->set(array(
                'id'       => $this->key,
                'secret'   => $this->secret,
                'redirect_uri' => 'https://my.pomodoneapp.com/supplier/auth/',
                'scope' => 'board:write user:read'
            ))
            # oauth flow
            ->authorize('https://app.gitkraken.com/oauth/authorize');
    }

    /**
     * @param $token
     * @param $secret
     * @return array
     */
    public function authorize($token, $secret)
    {
        try {
            $oauth_token_info = [
                'oauth_token_secret' => ''
            ];
            Auth2::legs(3)
                # configuration
                ->set(array(
                    'id'       => $this->key,
                    'secret'   => $this->secret,
                    'redirect_uri' => 'https://my.pomodoneapp.com/supplier/auth/',
                    'scope' => 'board:write user:read'
                ))
                # oauth flow
                ->authorize('https://app.gitkraken.com/oauth/authorize')
                ->access('https://api.gitkraken.com/oauth/access_token')
                ->finally(function($data) use(&$oauth_token_info) {
                    $oauth_token_info['oauth_token'] = $data['access_token'];
                });
            return $oauth_token_info;
        } catch(\Exception $ex) {
        }

        return [];

    }

    /**
     * @param array $service
     * @param bool $short_output
     * @return array
     */
    public function getContainers(array $service, $short_output = true)
    {

        $template_data = [];

        try {
            $boards = [];

            if(!$short_output) {
                return $boards;
            }

            $client = $this->createClient($service);

            $raw_boards = $client->get('boards')->json();

            foreach ($raw_boards as $board) {
                $boards[] = [
                    'id' => (string)$board['id'],
                    'title' => $board['name'],
                    'accessLevel' => 0,
                    'type' => 'project',
                ];
            }

            if (array_key_exists('datasets', $service) and !empty($service['datasets'])) {
                $template_data['selected_datasets'] = $service['datasets'];

                $selected = array_column($service['datasets'], 'id');
                $boards = array_filter($boards, function ($b) use ($selected) {
                    return !in_array($b['id'], $selected);
                });

            } else {
                $template_data['selected_datasets'][] = array_shift($boards);

                $this->updateServiceAccount([
                    'datasets' => $template_data['selected_datasets']
                ]);
            }


            $template_data['datasets'] = array_values($boards);
            $template_data['accounts'] = $service;
        } catch (\Exception $ex) {

        }


        return $template_data;
    }

    /**
     * @param array $service
     * @param array $filters
     * @return array
     */
    public function itemsFromSelectedContainers(array $service, array $filters = [])
    {

        $user = $this->app['session']->get('user');
        $user_object = new User($this->app['db']);
        $user_object->load($user['_id']);

        $user_tz = $user_object->getTimezone();

        $final_json = [
            'cards' => []
        ];

        try {
            $client = $this->createClient($service);

            $service['datasets'] = \A::get($service, 'datasets', []);

            $sort = $this->getSort($service);

            $final_json['sources'][] = [
                'uuid' => self::NAME,
                'title' => self::TITLE,
                'sortIndex' => $sort,
                'editable_fields' => $this->getEditableFields()
            ];

            $raw_user = $client->get('user', ['query' => ['fields' => ['name']]])->json();

            $final_json['members'] = [
                $this::NAME => [
                    'me' => Utils::extractServiceUserFields($raw_user)
                ]
            ];

            foreach($service['datasets'] as $index => $board) {

                $final_json['projects'][] = [
                    'uuid' => "{$service['_id']}-board-{$board['id']}",
                    'source' => self::NAME,
                    'title' => $board['title'],
                    'sortIndex' => $index,
                    'accessLevel' => 0,
                    'can_create_new' => $this->canCreateNew()
                ];

                $raw_board = $client->get("boards/{$board['id']}", ['query' => ['fields' => ['members', 'columns']]])->json();

                $members = \A::get($raw_board, 'members', []);
                foreach ($members as $member) {
                    $final_json['members'][$this::NAME]["{$service['_id']}-board-{$board['id']}"][] = Utils::extractServiceUserFields($member);
                }

                foreach($raw_board['columns'] as $sort => $list) {
                    $final_json['lists'][] = [
                        'uuid' => "{$service['_id']}-list-{$list['id']}",
                        'source' => self::NAME,
                        'title' => $list['name'],
                        'parent' => "{$service['_id']}-board-{$board['id']}",
                        'default' => ($sort === 0),
                        'can_create_new' => $this->canCreateNew(),
                    ];
                }

                $cards = $client->get("boards/{$board['id']}/cards", ['query' => ['fields' => ['name', 'assignees', 'board_id', 'column_id',  'due_date', 'labels', 'description']]])->json();

                foreach($cards as $card_position => $card) {


                    $task_card = [
                        'title' => $card['name'],
                        'source' => self::NAME,
                        'uuid' => (string)$card['id'],
                        'parent' => "{$service['_id']}-list-{$card['column_id']}",
                        'permalink' => "https://app.gitkraken.com/glo/board/{$board['id']}/card/{$card['id']}",
                        'desc' => \A::get($card, 'description/text', ''),
                        'member' => array_column($card['assignees'], 'id'),
                        'completable' => false,
                        'editable' => false,
                        'show_time' => false,
                    ];

                    $task_card['parents'] = [
                        $task_card['parent'] => [
                            'uuid' => $task_card['parent'],
                            'is_primary' => true,
                            'sortIndex' => (int)$card_position,
                            'label' => implode(', ', array_column($card['labels'], 'name'))
                        ]
                    ];




                    $final_json['cards'][] = $task_card;
                }



            }


        } catch (\Exception $ex) {
            echo  $ex->getMessage();
            exit();
        }

        $final_json['cards'] = array_values($final_json['cards']);

        return $final_json;
    }



    protected function createClient($service)
    {
        return new GuzzleHttpClient([
            'base_url' => 'https://gloapi.gitkraken.com/v1/glo/',
            'defaults' => [
                'headers' => [
                    'Authorization' => "Bearer {$service['oauth_token']}",
                ]
            ]
        ]);
    }
}
