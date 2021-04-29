#!/usr/bin/ruby -w

require 'inifile'
require 'mysql2'
require 'json'
require 'httparty'

def submitToZoho data, type, zoho, id
	post = {}
	post['data'] = Array.new
	post['data'] << data
	ret = HTTParty.post('https://www.zohoapis.com/crm/v2/Leads',
		:body => post.to_json,
		:headers => {
			'Content-Type' => 'application/json',
			'Authorization' => "Zoho-oauthtoken #{zoho['zohov2.' + type + '.access_token']}"
		}
	)
	result = JSON.parse(ret.response.body)
	if result.has_key?('status') && result['status'] == 'error'
		if result['code'] == 'INVALID_TOKEN'
			zoho = refreshAccessToken zoho, type;
			if zoho  == false
				puts "Failed to refresh token."
				exit
			else
				return submitToZoho data, type, zoho, id
			end
		else
			puts result['code']
			return false
		end
	elsif result.has_key?('data') && result['data'].length > 0 && result['data'][0].has_key?('code') && result['data'][0]['code'] != 'SUCCESS'
		if result['data'][0]['code'] == 'INVALID_DATA'
			data.delete(result['data'][0]['details']['api_name'])
			return submitToZoho data, type, zoho, id
		else
			puts "Failed to submit #{id}"
			return false;
		end
	else
		puts "Success #{id}"
		return true
	end
end

def refreshAccessToken zoho, type
	ret = HTTParty.post('https://accounts.zoho.com/oauth/v2/token',
		:body => {
			'grant_type' => 'refresh_token',
			'client_id' => zoho['zohov2.' + type + '.id'],
			'client_secret' => zoho['zohov2.' + type + '.secret'],
			'refresh_token' => zoho['zohov2.' + type + '.refresh_token']
		},
		:headers => {
			'Content-Type' => 'application/x-www-form-urlencoded'
		}
	)
	result = JSON.parse(ret.response.body)
	if result.has_key?('access_token')
		zoho['zohov2.' + type + '.access_token'] = result['access_token']
		return zoho
	end
	return false
end

config = IniFile.load('configuration.ini')

submissionIds = Array.new
text = File.open("submissions.txt").read
text.gsub!(/\r\n?/, "\n")
text.each_line do |line|
	submissionIds << line
end

begin
	con = Mysql2::Client.new(:host => "#{config['database']['host']}", :username => "#{config['database']['user']}", :password => "#{config['database']['password']}", :database => "#{config['database']['db']}")

	# get config
	rs = con.query("SELECT SettingName,SettingValue FROM #{config['database']['dbprefix']}rsform_config WHERE SettingName LIKE 'zohov2%'")
	if rs.count < 8
		puts "Zoho config not found."
		return
	end
	zoho = {}
	rs.each do |row|
		zoho[row['SettingName']] = row['SettingValue']
	end

	# process data
	submissionIds.each do |submissionId|
		data = {}

		# get form id
		rs = con.query("SELECT FormId FROM #{config['database']['dbprefix']}rsform_submissions WHERE SubmissionId = #{submissionId}")
		if rs.count == 0
			next
		end
		formId = rs.first['FormId']

		# get map / type
		rs = con.query("SELECT map,type FROM #{config['database']['dbprefix']}rsform_zohov2 WHERE form_id = #{formId} AND published = 1")
		if rs.count == 0
			next
		end
		res = rs.first
		map = JSON.parse(res['map'])
		type = res['type']

		# get fields
		rs = con.query("SELECT FieldName,FieldValue FROM #{config['database']['dbprefix']}rsform_submission_values WHERE SubmissionId = #{submissionId}")
		if rs.count == 0
			next
		end
		rs.each do |row|
			data[map[row['FieldName']]] = row['FieldValue']
		end

		if data.has_key?('First_Name') && (!data.has_key?('Last_Name') || data['Last_Name'] == '')
			data['Last_Name'] = 'Not Provided'
		end

		# post to Zoho
		success = submitToZoho data, type, zoho, submissionId

		puts success
	end

rescue Mysql2::Error => e
	puts e.errno
	puts e.error
ensure
	con.close if con
end

puts "Finished.";