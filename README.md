### OBJECTIVE
This template has been implemented in order to develop **WhatsApp / SMS** bots that consume from the Inbenta Chatbot API and Twilio API with the minimum configuration and effort. It uses some libraries to connect the Chatbot API with Twilio. The main library of this template is Twilio Connector, which extends from a base library named [Chatbot API Connector](https://github.com/inbenta-integrations/chatbot_api_connector), built to be used as a base for different external services like Facebook, Skype, Line, etc.

This template includes **/conf** and **/lang** folders, which have all the configuration and translations required by the libraries, and a small file **server.php** which creates a TwilioConnector’s instance in order to handle all the incoming requests.

### FUNCTIONALITIES
This bot template inherits the functionalities from the `ChatbotConnector` library. Currently, the features provided by this application are:

* Simple answers
* Multiple options (without buttons, selection by number).
* Polar questions (without buttons, selection by number).
* Chained answers.
* Send information to webhook through forms.
* Escalate to HyperChat after a number of no-results answers.
* Escalate to HyperChat when matching with an 'Escalation FAQ'.
* Send a survey URL linked to the chat ticket.

>**NOTE:** WhatsApp / SMS doesn't allow displaying buttons, so **Multiple options**, **Polar questions**, **Chained answers**, **ratings** and **escalation question** will be asked without buttons, the user will select the desired option by text.

### WHATSAPP / SMS VALID MESSAGES
Due to **Twilio API** limitations, only the following messages types are supported by the connector:
* Text
* Image
* Audio
* Video
* Files
* Static location

### INSTALLATION
It's pretty simple to get this UI working. The mandatory configuration files are included by default in `/conf/custom` to be filled in, so you have to provide the information required in these files:

* **File 'api.php'**
    Provide the API Key and API Secret of your Chatbot Instance.

* **File 'chat.php'**
    Provide the configuration for Hyperchat Instance.

* **File 'twilio.php'**
    Provide the Twilio credentials. These credentials are formed by SID and Auth Token.

* **File 'environments.php'**
    Here you can define regexes to detect `development` and `preproduction` environments. If the regexes do not match the current conditions or there isn't any regex configured, `production` environment will be assumed.


#### OPTIONAL EXTRA INFO CONFIGURATION
The following settings must be set in the configuration files but optionally they can be set using Extra Info data in backstage. 
* Label translations
* Only chat mode configuration

When new data has been set, you should publish ExtraInfo by clicking the **Post** button.

##### TRANSLATIONS
Manage the translation labels from ExtraInfo. Here are the steps to create the translations object in ExtraInfo:
* Go to **Manage groups and types -> twilio -> Add type**. Name it `translations` and add a new property with type `Multiple` named with your Chatbot's language label (en, es, it...).
* Inside your language, add all the labels that you want to override. Each label should be a `Text`entry (you can find the labels list below).
* Save your translations object.

Now you can create the ExtraInfo object by clicking the **New entry** button, selecting the `translations` type and naming it as `translations`. Then, fill each label with your desired translation and remember to publish ExtraInfo by clicking the **Post** button.

Here you have the current labels with their English value:
* **agent_joined** => 'Agent $agentName has joined the conversation.',
* **api_timeout** => 'Please, reformulate your question.',
* **ask_rating_comment** => 'Please tell us why',
* **ask_to_escalate** => 'Do you want to start a chat with a human agent?',
* **chat_closed** => 'Chat closed',
* **creating_chat** => 'I will try to connect you with an agent. Please wait.',
* **error_creating_chat** => 'There was an error joining the chat',
* **escalation_rejected** => 'What else can I do for you?',
* **no** => 'No',
* **no_agents** => 'No agents available',
* **queue_estimation_first** => 'There is one person ahead of you.',
* **queue_estimation** => 'There are $queuePosition people ahead of you.',
* **rate_content_intro** => 'Was this answer helpful?',
* **thanks** => 'Thanks!',
* **yes** => 'Yes',
* **close_chat_key_word** => '/close',
* **out_of_time** => '_There are no agents connected_',
* **queue_warning** => 'Wait until an agent is connected before making a question.',
* **no_rating_given** => 'We understand you do not want to rate.'


##### ONLY CHAT MODE
Here are the steps to create the `only_chat_mode` ExtraInfo object to enable/disable the only-chat mode:
* Go to **Manage groups and types -> twilio -> Add type**. Name it `settings` and add a new property with type `Text` named with `only_chat_mode`.
* Save your translations object.

Now you can create the ExtraInfo object by clicking the **New entry** button, selecting the `settings` type and naming it as `only_chat_mode`. Then, fill it using **true** or **false** and remember to publish ExtraInfo by clicking the **Post** button.


### HOW TO CUSTOMIZE
**From configuration**

For a default behavior, the only requisite is to fill the basic configuration (more information in `/conf/README.md`). There are some extra configuration parameters in the configuration files that allow you to modify the basic-behavior.


**Custom Behaviors**

If you need to customize the bot flow, you need to extend the class `TwilioConnector`, included in the `/lib/TwilioConnector` folder. You can modify 'TwilioConnector' methods and override all the parent methods from `ChatbotConnector`.

For example, when the bot is configured to escalate with an agent, a conversation in HyperChat starts. If your bot needs to use an external chat service, you should override the parent method `escalateToAgent` and set up the external service:
```php
    //Tries to start a chat with an agent with an external service
    protected function escalateToAgent()
    {
        $useExternalService = $this->conf->get('chat.useExternal');
        
        if ($useExternalService) {
            // Inform the user that the chat is being created
            $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('creating_chat')));
            
            // Create a new instance for the external client
            $externalChat = New SomeExternalChatClass($this->conf->get('chat.externalConf'));
            $externalChat->openChat();
        } else {
            // Use the parent method to escalate to HyperChat
            parent::escalateToAgent();
        }
    }
```


**HyperChat escalation by no-result answer and negative content rating**

If your bot needs integration with HyperChat, fill the chat configuration at `/conf/conf-path/chat.php` and subscribe to the following events on your Backstage instance: `invitations:new`, `invitations:accept`, `forever:alone`, `chats:close`, `messages:new`. When subscribing to the events in Backstage, you have to point to the `/server.php` file in order to handle the events from HyperChat.

Configuration parameter `triesBeforeEscalation` sets the number of no-results answers after which the bot should escalate to an agent. Parameter `negativeRatingsBeforeEscalation` sets the number of negative ratings after which the bot should escalate to an agent.


**Escalation with FAQ**

If your bot has to escalate to HyperChat when matching a specific FAQ, the content needs to meet a few requisites:
- Dynamic setting named `ESCALATE`, non-indexable, visible, `Text` box-type with `Allow multiple objects` option checked
- In the content, add a new object to the `Escalate` setting (with the plus sign near the setting name) and type the text `TRUE`.

After a Restart Project Edit and Sync & Restart Project Live, your bot should escalate when this FAQ is matched.
Note that the `server.php` file has to be subscribed to the required HyperChat events as described in the previous section.

#### SPECIAL COMMANDS
A **key word** has been created to allow users close the chat when it has been escalated. This command can be updated from the language configuration or extraInfo labels:
* **close_chat_key_word** => '/close',

### DEPENDENCIES
This application imports `inbenta/chatbot-api-connector` as a Composer dependency, that includes `symfony/http-foundation@^3.1` and `guzzlehttp/guzzle@~6.0` as dependencies too.

### TWILIO PROCESS
To get started with the integration, you must have a [Twilio](https://www.twilio.com/) account. Inside the Twilio dashboard you can find the "**Account SID**" and the "**Auth Token**", as well as additional settings. To learn more about it, you can refer to the file [Instructions.pdf](https://github.com/inbenta-integrations/twilio-chatbot/blob/master/Instructions.pdf), into the section "Prepare the Twilio environment".