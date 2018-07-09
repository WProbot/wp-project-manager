<?php

namespace WeDevs\PM\Project\Controllers;

use WP_REST_Request;
use WeDevs\PM\Project\Models\Project;
use League\Fractal;
use League\Fractal\Resource\Item as Item;
use League\Fractal\Resource\Collection as Collection;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use WeDevs\PM\Common\Traits\Transformer_Manager;
use WeDevs\PM\Project\Transformers\Project_Transformer;
use WeDevs\PM\Common\Traits\Request_Filter;
use WeDevs\PM\User\Models\User;
use WeDevs\PM\User\Models\User_Role;
use WeDevs\PM\Category\Models\Category;
use WeDevs\PM\Common\Traits\File_Attachment;
use Illuminate\Pagination\Paginator;

class Project_Controller {

	use Transformer_Manager, Request_Filter, File_Attachment;

	public function index( WP_REST_Request $request ) {
		$per_page = $request->get_param( 'per_page' );
		$page     = $request->get_param( 'page' );
		$status   = $request->get_param( 'status' );
		$category = $request->get_param( 'category' );

		$per_page_from_settings = pm_get_settings( 'project_per_page' );
		$per_page_from_settings = $per_page_from_settings ? $per_page_from_settings : 15;

		$per_page = $per_page ? $per_page : $per_page_from_settings;
		$page     = $page ? $page : 1;

		Paginator::currentPageResolver(function () use ($page) {
            return $page;
        }); 

		$projects = $this->fetch_projects( $category, $status );
		$projects = apply_filters( 'pm_project_query', $projects, $request->get_params() );
		if( $per_page == 'all' ) {
			$project_collection = $projects->get();
			$resource = new Collection( $project_collection, new Project_Transformer );
			$resource->setMeta( $this->projects_meta( $category ) );
			return $this->get_response( $resource );
		}
		
		if ( $per_page == '-1' ) {
			$per_page = $projects->count();
		}

		$projects = $projects->paginate( $per_page );

		$project_collection = $projects->getCollection();
		$resource = new Collection( $project_collection, new Project_Transformer );

		$resource->setMeta( $this->projects_meta( $category ) );

        $resource->setPaginator( new IlluminatePaginatorAdapter( $projects ) );
        
        return $this->get_response( $resource );
    }

    private function projects_meta( $category ) {
		$eloquent_sql     = $this->fetch_projects_by_category( $category );
		$total_projects   = $eloquent_sql->count();
		$eloquent_sql     = $this->fetch_projects_by_category( $category );
		$total_incomplete = $eloquent_sql->where( 'status', Project::INCOMPLETE )->count();
		$eloquent_sql     = $this->fetch_projects_by_category( $category );
		$total_complete   = $eloquent_sql->where( 'status', Project::COMPLETE )->count();
		$eloquent_sql     = $this->fetch_projects_by_category( $category );
		$total_pending    = $eloquent_sql->where( 'status', Project::PENDING )->count();
		$eloquent_sql     = $this->fetch_projects_by_category( $category );
		$total_archived   = $eloquent_sql->where( 'status', Project::ARCHIVED )->count();
		$user_id          = get_current_user_id();

		$meta  = [
			'total_projects'   => $total_projects,
			'total_incomplete' => $total_incomplete,
			'total_complete'   => $total_complete,
			'total_pending'    => $total_pending,
			'totla_archived'   => $total_archived,
		];

		return $meta;
    }

    private function fetch_projects( $category, $status ) {
    	$projects = $this->fetch_projects_by_category( $category );

    	if ( in_array( $status, Project::$status ) ) {
			$status   = array_search( $status, Project::$status );
			$projects = $projects->where( 'status', $status );
		}

		return $projects;
    }

    private function fetch_projects_by_category( $category = null ) {
    	$category = Category::where( 'categorible_type', 'project' )
    		->where( 'id', $category )
    		->first();
    	$user_id = get_current_user_id();
    	if ( $category ) {
    		$projects = $category->projects()->orderBy( 'created_at', 'DESC' );
    	} else {
    		$projects = Project::orderBy( 'created_at', 'DESC' );
    	}
    	if ( !pm_has_manage_capability( $user_id ) ){
    		$projects = $projects->whereHas('assignees', function( $q ) use ( $user_id ) {
    					$q->where('user_id', $user_id );
    				});
    	}
    	
    	return $projects;
    }

	public function show( WP_REST_Request $request ) {
		$id 	  = $request->get_param('id');
		$user_id  = get_current_user_id();
		$project  =  Project::find( $id );

		if ( !$project  ) {
			return new \WP_Error( 'project', pm_get_text('success_messages.no_project'), array( 'status'=> 404 ) );
		}

		$resource = new Item( $project, new Project_Transformer );
		$list_view = pm_get_meta( $user_id, $id, 'list_view', 'list_view_type' );
		$resource->setMeta([
			'list_view_type' => $list_view ? $list_view->toArray() : null
		]);

        return $this->get_response( $resource );
	}

	public function store( WP_REST_Request $request ) {
		// Extraction of no empty inputs and create project
		$data    = $this->extract_non_empty_values( $request );
		$project = Project::create( $data );

		// Establishing relationships
		$category_ids = $request->get_param( 'categories' );
		if ( $category_ids ) {
			$project->categories()->sync( $category_ids );
		}

		$assignees = $request->get_param( 'assignees' );
		$assignees[] = [
			'user_id' => wp_get_current_user()->ID,
			'role_id' => 1, // 1 for manager
		];

		if ( is_array( $assignees ) ) {
			$this->assign_users( $project, $assignees );
		}
		do_action( 'pm_project_new', $project, $request->get_params());
		// Transforming database model instance
		$resource = new Item( $project, new Project_Transformer );
		$response = $this->get_response( $resource );
		$response['message'] = pm_get_text('success_messages.project_created');
		do_action( 'cpm_project_new', $project->id, $project->toArray(), $request->get_params() ); // will deprecated 
		do_action( 'pm_after_new_project', $response, $request->get_params() );

        return $response;
	}

	public function update( WP_REST_Request $request ) {
		// Extract non empty inputs and update project
		$data    = $this->extract_non_empty_values( $request );
		$project = Project::find( $data['id'] );

		$project->update_model( $data );

		// Establishing relationships
		$category_ids = $request->get_param( 'categories' );
		if ( $category_ids ) {
			$project->categories()->sync( $category_ids );
		}

		$assignees = $request->get_param( 'assignees' );

		if ( is_array( $assignees ) ) {
			$project->assignees()->detach();
			$this->assign_users( $project, $assignees );
		}

		$resource = new Item( $project, new Project_Transformer );
		$response = $this->get_response( $resource );
		$response['message'] = pm_get_text('success_messages.project_updated');
		do_action( 'cpm_project_update', $project->id, $project->toArray(), $request->get_params() );
		do_action( 'pm_after_update_project', $response, $request->get_params() );
		
        return $response;
	}

	public function destroy( WP_REST_Request $request ) {
		$id = $request->get_param('id');

		// Find the requested resource
		$project =  Project::find( $id );
		do_action( 'cpm_delete_project_prev', $id ); // will be deprecated 
		do_action( 'cpm_project_delete', $id, true );
		do_action( 'pm_before_delete_project', $project, $request->get_params() );
		// Delete related resourcess
		$project->categories()->detach();

		$tasks = $project->tasks;
        foreach ( $tasks as $task ) {
            $task->files()->delete();
            $task->assignees()->delete();
            $task->metas()->delete();
        }
		$project->tasks()->delete();

		$task_lists = $project->task_lists;
		foreach ( $task_lists as $task_list ) {
			$task_list->boardables()->delete();	        
	        $task_list->metas()->delete();
	        $task_list->files()->delete();
		}
		$project->task_lists()->delete();

		$project->discussion_boards()->delete();
		$project->milestones()->delete();
		$project->comments()->delete();
		$project->assignees()->detach();
		$this->detach_files( $project );
		$project->settings()->delete();
		$project->activities()->delete();
		$project->meta()->delete();

		// Delete the main resource
		$project->delete();
		do_action( 'pm_after_delete_project', $request->get_params() );
		do_action( 'cpm_delete_project_after', $id );
		return [
			'message' => pm_get_text('success_messages.project_deleted')
		];
	}

	private function assign_users( Project $project, $assignees = [] ) {
		$assignees = is_array( $assignees ) ? $assignees : []; 
		
		foreach ( $assignees as $assignee ) {
			User_Role::firstOrCreate([
				'user_id'    => $assignee['user_id'],
				'role_id'    => $assignee['role_id'],
				'project_id' => $project->id,
			]);
		}
	}


}