<?php

namespace api\v1;

use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use \api\ApiController as ApiController;
use \Rule;
use \NginxConfig;
use \Setting;

class RulesController extends ApiController
{

    public function __construct()
    {
        // Will requrire that the API authenticiation is reqired!
        parent::__construct();
    }

    /**
     * Retreieve a list of all the rules and rule targets.
     * @return Response
     */
    public function index()
    {

        $rules = Rule::all();

        // Lets load in each of the rules and get the target infomation for each..
        $combined_list = array();
        foreach ($rules as $rule) {
            $combined = array();

            // Now we load in each config file...
            $reader = new NginxConfig();
            $reader->setHostheaders($rule->hostheader);
            $reader->readConfig(Setting::getSetting('nginxconfpath') . '/' . $reader->serverNameToFileName() . '.enabled.conf');
            $targets = $reader->writeConfig()->toJSON();
            $combined['hostheader'] = $rule->hostheader;
            $target_array = json_decode($targets, true);
            $target_string = '';
            $total_hosts = 0;
            $combined['id'] = $rule->id;
            $combined['targets'] = $target_array['nlb_servers'];
            $combined['enabled'] = true;
            if ($total_hosts > 1) {
                $combined['nlb'] = true;
            } else {
                $combined['nlb'] = false;
            }
            $combined_list[] = $combined;
        }
        return Response::json(array(
                    'error' => false,
                    'rules' => $combined_list
                        ), 200);
    }

    /**
     * Retrieve an existing rule by it's database ID.
     * @param int $id The ID of the rule to return.
     * @return Response
     */
    public function show($id)
    {
        $rule = Rule::find($id);
        if ($rule)
            return Response::json(array(
                        'error' => false,
                        'rule' => $rule->toArray()
                            ), 200);
        return Response::json(array(
                    'error' => true,
                    'message' => 'The requested rule does not exist.'
                        ), 404);
    }

    /**
     * Create a new rule
     * @return Response
     */
    public final function store()
    {
        // we'll first validate the data before continueing...
        $validator = Validator::make(
                        array(
                    'origin' => Input::get('origin'),
                    'target' => Input::get('target'),
                        )
                        , array(
                    'origin' => array('required', 'unique:rules,hostheader'),
                    'target' => array('required'),
                        )
        );

        if ($validator->passes()) {
            $create_rule = new Rule;
            $create_rule->hostheader = strtolower(Input::get('origin'));
            $create_rule->enabled = true;
            $create_rule->nlb = false;
            if ($create_rule->save()) {
                // We now write out the configuration file for the nginx virtual host.
                $config = new NginxConfig();
                $config->setHostheaders($create_rule->hostheader);
                $config->addServerToNLB(array(
                    strtolower(Input::get('target')),
                    array(
                        'weight' => 1,
                        'max_fails' => Setting::getSetting('maxfails'),
                        'fail_timeout' => Setting::getSetting('failtimeout'),
                    )
                ));
                $config->writeConfig();
                $config->toFile(Setting::getSetting('nginxconfpath') . '/' . $config->serverNameToFileName() . '.enabled.conf');
                $config->reloadConfig();
                return Response::json(array(
                            'error' => false,
                            'message' => 'Rule created',
                                ), 201);
            }
            return Response::json(array(
                        'error' => true,
                        'message' => 'The rule could not be created, please contact the server admin!'
                            ), 500);
        } else {
            return Response::json(array(
                        'error' => true,
                        'message' => $validator->messages()->all()
                            ), 400);
        }
    }

    /**
     * Update an existing rule.
     * @param int $id The database ID of the rule to be updated.
     * @return Response
     */
    public function update($id)
    {
        return Response::json(array(
                    'error' => false,
                    'message' => 'Rule updated'
                        ), 200);
    }

    /**
     * Delete an existing rule by ID.
     * @param int $id The database ID of the rule to be deleted.
     * @return Response
     */
    public function destroy($id)
    {
        $delete_rule = Rule::find($id);
        if ($delete_rule) {
            $config = new NginxConfig();
            $config->setHostheaders($delete_rule->hostheader);
            $config->readConfig(Setting::getSetting('nginxconfpath') . '/' . $config->serverNameToFileName() . '.enabled.conf');
            if ($config->deleteConfig(Setting::getSetting('nginxconfpath') . '/' . $config->serverNameToFileName() . '.enabled.conf')) {
                $delete_rule->delete();
            }
            $config->reloadConfig();
            return Response::json(array(
                        'error' => false,
                        'message' => 'Rule deleted'
                            ), 200);
        }
        return Response::json(array(
                    'error' => false,
                    'message' => 'Requested rule does not exist.'
                        ), 404);
    }

}

?>
