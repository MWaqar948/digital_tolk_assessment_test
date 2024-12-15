<?php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\UserMeta;
use App\Models\UsersBlacklist;

class UserRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_customer_with_paid_consumer_type_and_no_company_id()
    {
        $request = [
            'role' => env('CUSTOMER_ROLE_ID'),
            'name' => 'Alice Johnson',
            'company_id' => '',
            'consumer_type' => 'paid',
            'customer_type' => 'individual',
            'username' => 'alicej',
            'email' => 'alice.johnson@example.com',
            'password' => 'securePassword1',
            'post_code' => '56789',
            'address' => '456 Elm St',
            'city' => 'Metropolis',
            'town' => 'Smalltown',
            'country' => 'Utopia',
            'reference' => 'yes',
            'additional_info' => 'Regular customer',
            'cost_place' => 'CP001',
            'fee' => '100.00',
            'time_to_charge' => '30',
            'time_to_pay' => '15',
            'charge_ob' => 'OB123',
            'customer_id' => 'CUST789',
            'charge_km' => '0.50',
            'maximum_km' => '500',
        ];

        $user = app(UserRepository::class)->createOrUpdate(null, $request);

        $this->assertNotNull($user);
        $this->assertEquals('Alice Johnson', $user->name);
        $this->assertEquals(env('CUSTOMER_ROLE_ID'), $user->user_type);
        $this->assertNotNull($user->company_id);

        $userMeta = UserMeta::where('user_id', $user->id)->first();
        $this->assertNotNull($userMeta);
        $this->assertEquals('paid', $userMeta->consumer_type);
        $this->assertEquals('individual', $userMeta->customer_type);
        $this->assertEquals('alicej', $userMeta->username);
        $this->assertEquals('56789', $userMeta->post_code);
        $this->assertEquals('456 Elm St', $userMeta->address);
        $this->assertEquals('Metropolis', $userMeta->city);
        $this->assertEquals('Smalltown', $userMeta->town);
        $this->assertEquals('Utopia', $userMeta->country);
        $this->assertEquals('yes', $userMeta->reference);
        $this->assertEquals('Regular customer', $userMeta->additional_info);
        $this->assertEquals('CP001', $userMeta->cost_place);
        $this->assertEquals('100.00', $userMeta->fee);
        $this->assertEquals('30', $userMeta->time_to_charge);
        $this->assertEquals('15', $userMeta->time_to_pay);
        $this->assertEquals('OB123', $userMeta->charge_ob);
        $this->assertEquals('CUST789', $userMeta->customer_id);
        $this->assertEquals('0.50', $userMeta->charge_km);
        $this->assertEquals('500', $userMeta->maximum_km);
    }

    public function test_create_customer_with_blacklist()
    {
        $translator = User::factory()->create(['user_type' => env('TRANSLATOR_ROLE_ID')]);
        $request = [
            'role' => env('CUSTOMER_ROLE_ID'),
            'name' => 'Bob Smith',
            'company_id' => '',
            'consumer_type' => 'free',
            'customer_type' => 'business',
            'username' => 'bobsmith',
            'email' => 'bob.smith@example.com',
            'password' => 'securePassword2',
            'post_code' => '98765',
            'address' => '789 Oak St',
            'city' => 'Capital City',
            'translator_ex' => [$translator->id],
        ];

        $user = app(UserRepository::class)->createOrUpdate(null, $request);

        $this->assertNotNull($user);
        $this->assertEquals(env('CUSTOMER_ROLE_ID'), $user->user_type);

        $userMeta = UserMeta::where('user_id', $user->id)->first();
        $this->assertNotNull($userMeta);
        $this->assertEquals('free', $userMeta->consumer_type);
        $this->assertEquals('business', $userMeta->customer_type);
        $this->assertEquals('bobsmith', $userMeta->username);
        $this->assertEquals('98765', $userMeta->post_code);
        $this->assertEquals('789 Oak St', $userMeta->address);
        $this->assertEquals('Capital City', $userMeta->city);

        $blacklist = UsersBlacklist::where('user_id', $user->id)->where('translator_id', $translator->id)->first();
        $this->assertNotNull($blacklist);
    }

    public function test_create_translator_with_languages_and_towns()
    {
        $languages = [1, 2, 3];
        $towns = [1, 2];

        $request = [
            'role' => env('TRANSLATOR_ROLE_ID'),
            'name' => 'Charlie Davis',
            'email' => 'charlie.davis@example.com',
            'password' => 'securePassword3',
            'translator_type' => 'freelancer',
            'worked_for' => 'yes',
            'organization_number' => '654321987',
            'gender' => 'non-binary',
            'translator_level' => 'certified',
            'user_language' => $languages,
            'user_towns_projects' => $towns,
            'new_towns' => 'New Babel',
            'status' => '1',
            'post_code' => '43210',
            'address' => '123 Main St',
            'address_2' => 'Suite 100',
            'town' => 'Oldtown',
            'additional_info' => 'Experienced translator',
        ];

        $user = app(UserRepository::class)->createOrUpdate(null, $request);

        $this->assertNotNull($user);
        $this->assertEquals('Charlie Davis', $user->name);
        $this->assertEquals(env('TRANSLATOR_ROLE_ID'), $user->user_type);

        $userMeta = UserMeta::where('user_id', $user->id)->first();
        $this->assertNotNull($userMeta);
        $this->assertEquals('freelancer', $userMeta->translator_type);
        $this->assertEquals('yes', $userMeta->worked_for);
        $this->assertEquals('654321987', $userMeta->organization_number);
        $this->assertEquals('non-binary', $userMeta->gender);
        $this->assertEquals('certified', $userMeta->translator_level);
        $this->assertEquals('43210', $userMeta->post_code);
        $this->assertEquals('123 Main St', $userMeta->address);
        $this->assertEquals('Suite 100', $userMeta->address_2);
        $this->assertEquals('Oldtown', $userMeta->town);
        $this->assertEquals('Experienced translator', $userMeta->additional_info);

        foreach ($languages as $languageId) {
            $this->assertDatabaseHas('user_languages', [
                'user_id' => $user->id,
                'lang_id' => $languageId,
            ]);
        }

        foreach ($towns as $townId) {
            $this->assertDatabaseHas('user_towns', [
                'user_id' => $user->id,
                'town_id' => $townId,
            ]);
        }

        $this->assertDatabaseHas('towns', [
            'townname' => 'New Babel',
        ]);

        $this->assertEquals('1', $user->status);
    }

    public function test_update_customer()
    {
        $user = User::factory()->create(['user_type' => env('CUSTOMER_ROLE_ID')]);
        UserMeta::factory()->create(['user_id' => $user->id]);

        $request = [
            'role' => env('CUSTOMER_ROLE_ID'),
            'name' => 'Updated Alice',
            'company_id' => '1',
            'consumer_type' => 'free',
            'customer_type' => 'business',
            'username' => 'updatedalice',
            'email' => 'updated.alice@example.com',
            'post_code' => '11111',
            'address' => '789 Updated St',
            'city' => 'Updated City',
            'reference' => 'no',
            'additional_info' => 'Updated customer',
            'cost_place' => 'UpdatedCP',
            'fee' => '200.00',
            'time_to_charge' => '45',
            'time_to_pay' => '20',
            'charge_ob' => 'UpdatedOB',
            'customer_id' => 'UpdatedCUST',
            'charge_km' => '1.00',
            'maximum_km' => '1000',
        ];

        $updatedUser = app(UserRepository::class)->createOrUpdate($user->id, $request);

        $this->assertNotNull($updatedUser);
        $this->assertEquals('Updated Alice', $updatedUser->name);

        $updatedMeta = UserMeta::where('user_id', $updatedUser->id)->first();
        $this->assertNotNull($updatedMeta);
        $this->assertEquals('free', $updatedMeta->consumer_type);
        $this->assertEquals('business', $updatedMeta->customer_type);
        $this->assertEquals('updatedalice', $updatedMeta->username);
        $this->assertEquals('11111', $updatedMeta->post_code);
        $this->assertEquals('789 Updated St', $updatedMeta->address);
        $this->assertEquals('Updated City', $updatedMeta->city);
        $this->assertEquals('no', $updatedMeta->reference);
        $this->assertEquals('Updated customer', $updatedMeta->additional_info);
        $this->assertEquals('UpdatedCP', $updatedMeta->cost_place);
        $this->assertEquals('200.00', $updatedMeta->fee);
        $this->assertEquals('45', $updatedMeta->time_to_charge);
        $this->assertEquals('20', $updatedMeta->time_to_pay);
        $this->assertEquals('UpdatedOB', $updatedMeta->charge_ob);
        $this->assertEquals('UpdatedCUST', $updatedMeta->customer_id);
        $this->assertEquals('1.00', $updatedMeta->charge_km);
        $this->assertEquals('1000', $updatedMeta->maximum_km);
    }

    public function test_update_translator()
    {
        $user = User::factory()->create(['user_type' => env('TRANSLATOR_ROLE_ID')]);
        UserMeta::factory()->create(['user_id' => $user->id]);

        $languages = [1, 2];
        $towns = [3, 4];

        $request = [
            'role' => env('TRANSLATOR_ROLE_ID'),
            'name' => 'Updated Charlie',
            'translator_type' => 'agency',
            'worked_for' => 'no',
            'gender' => 'female',
            'translator_level' => 'expert',
            'post_code' => '54321',
            'address' => 'Updated Main St',
            'address_2' => 'Suite 200',
            'town' => 'Newtown',
            'additional_info' => 'Updated translator info',
            'user_language' => $languages,
            'user_towns_projects' => $towns,
        ];

        $updatedUser = app(UserRepository::class)->createOrUpdate($user->id, $request);

        $this->assertNotNull($updatedUser);
        $this->assertEquals('Updated Charlie', $updatedUser->name);

        $updatedMeta = UserMeta::where('user_id', $updatedUser->id)->first();
        $this->assertNotNull($updatedMeta);
        $this->assertEquals('agency', $updatedMeta->translator_type);
        $this->assertEquals('no', $updatedMeta->worked_for);
        $this->assertEquals('female', $updatedMeta->gender);
        $this->assertEquals('expert', $updatedMeta->translator_level);
        $this->assertEquals('54321', $updatedMeta->post_code);
        $this->assertEquals('Updated Main St', $updatedMeta->address);
        $this->assertEquals('Suite 200', $updatedMeta->address_2);
        $this->assertEquals('Newtown', $updatedMeta->town);
        $this->assertEquals('Updated translator info', $updatedMeta->additional_info);

        foreach ($languages as $languageId) {
            $this->assertDatabaseHas('user_languages', [
                'user_id' => $updatedUser->id,
                'lang_id' => $languageId,
            ]);
        }

        foreach ($towns as $townId) {
            $this->assertDatabaseHas('user_towns', [
                'user_id' => $updatedUser->id,
                'town_id' => $townId,
            ]);
        }
    }

}